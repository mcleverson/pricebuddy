"""pricebuddy-video HTTP API: renders the Remotion "Carousel" composition into an MP4.

It knows nothing about PriceBuddy. Callers send the props (JSON) and the media
files, then poll the render and download the video:

    POST /v1/renders                      multipart: props (JSON), media[] (files), cover_url (optional)
    GET  /v1/renders/{id}                 {status: queued|rendering|done|failed, progress, error}
    GET  /v1/renders/{id}/video           the MP4
    GET  /v1/renders/{id}/media/{name}    the job's media, read by the renderer itself
    GET  /v1/templates/carousel           the template options and their defaults
"""

from __future__ import annotations

import json
import logging
import mimetypes
import os
import queue
import re
import shutil
import subprocess
import threading
import time
import urllib.request
import uuid
from email.parser import BytesParser
from email.policy import default as email_policy
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

PORT = int(os.environ.get("VIDEO_PORT", "8200"))
DATA_DIR = Path(os.environ.get("VIDEO_DATA_DIR", "/data")) / "jobs"
REMOTION_DIR = Path(os.environ.get("VIDEO_REMOTION_DIR", "/opt/video/remotion"))
CONCURRENCY = os.environ.get("VIDEO_RENDER_CONCURRENCY", "2")
RENDER_TIMEOUT = int(os.environ.get("VIDEO_RENDER_TIMEOUT_SECONDS", "1800"))
JOB_TTL_SECONDS = int(os.environ.get("VIDEO_JOB_TTL_DAYS", "7")) * 86400
MAX_UPLOAD_BYTES = int(os.environ.get("VIDEO_MAX_UPLOAD_MB", "600")) * 1024 * 1024
MAX_COVER_BYTES = 15 * 1024 * 1024
REMOTION = str(REMOTION_DIR / "node_modules" / ".bin" / "remotion")

ID = r"[0-9a-f]{32}"


class RenderError(Exception):
    pass


def job_dir(job_id: str) -> Path:
    return DATA_DIR / job_id


def read_job(job_id: str) -> dict[str, Any] | None:
    try:
        return json.loads((job_dir(job_id) / "job.json").read_text())
    except (OSError, json.JSONDecodeError):
        return None


def write_job(job_id: str, **changes: Any) -> None:
    job = {**(read_job(job_id) or {}), **changes}
    tmp = job_dir(job_id) / "job.json.tmp"
    tmp.write_text(json.dumps(job))
    tmp.replace(job_dir(job_id) / "job.json")


def prune_jobs() -> None:
    cutoff = time.time() - JOB_TTL_SECONDS
    for path in DATA_DIR.iterdir():
        if path.is_dir() and path.stat().st_mtime < cutoff:
            shutil.rmtree(path, ignore_errors=True)


def media_type(content_type: str, filename: str) -> str | None:
    guessed = content_type if content_type not in ("", "application/octet-stream") else mimetypes.guess_type(filename)[0] or ""
    kind = guessed.split("/")[0]
    return kind if kind in ("image", "video") else None


def extension(content_type: str, filename: str) -> str:
    suffix = Path(filename).suffix.lower()
    if re.fullmatch(r"\.[a-z0-9]{1,5}", suffix):
        return suffix
    return mimetypes.guess_extension(content_type) or ""


def parse_multipart(content_type: str, body: bytes) -> tuple[dict[str, str], list[tuple[str, str, bytes]]]:
    """Fields and files (filename, content type, bytes) of a multipart/form-data body, in order."""
    message = BytesParser(policy=email_policy).parsebytes(
        b"MIME-Version: 1.0\r\nContent-Type: " + content_type.encode() + b"\r\n\r\n" + body)
    if not message.is_multipart():
        raise RenderError("expected multipart/form-data")
    fields: dict[str, str] = {}
    files: list[tuple[str, str, bytes]] = []
    for part in message.iter_parts():
        payload = part.get_payload(decode=True) or b""
        if part.get_filename():
            files.append((part.get_filename(), part.get_content_type(), payload))
        elif name := part.get_param("name", header="content-disposition"):
            fields[str(name)] = payload.decode()
    return fields, files


def download_cover(url: str) -> tuple[str, bytes]:
    if not re.match(r"https?://", url):
        raise RenderError("cover_url must be an http(s) URL")
    request = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0 (pricebuddy-video)"})
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            content_type = response.headers.get_content_type()
            body = response.read(MAX_COVER_BYTES + 1)
    except OSError as exc:
        raise RenderError(f"could not download cover_url: {exc}") from exc
    if not content_type.startswith("image/") or len(body) > MAX_COVER_BYTES:
        raise RenderError("cover_url is not an image up to 15 MB")
    return content_type, body


def video_seconds(path: Path) -> float | None:
    """Duration through the ffprobe that ships with Remotion."""
    try:
        result = subprocess.run(
            [REMOTION, "ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", str(path)],
            cwd=REMOTION_DIR, capture_output=True, text=True, timeout=60, check=True)
        return float(result.stdout.strip().splitlines()[-1])
    except (subprocess.SubprocessError, ValueError, IndexError):
        logging.warning("Could not read the duration of %s", path)
        return None


class Renderer:
    def __init__(self) -> None:
        self.jobs: queue.Queue[str] = queue.Queue()

    def create(self, fields: dict[str, str], files: list[tuple[str, str, bytes]]) -> dict[str, Any]:
        try:
            props = json.loads(fields.get("props") or "{}")
        except json.JSONDecodeError as exc:
            raise RenderError("props must be JSON") from exc
        if not isinstance(props, dict):
            raise RenderError("props must be a JSON object")

        uploads = list(files)
        if cover_url := fields.get("cover_url"):
            content_type, body = download_cover(cover_url)
            uploads.insert(0, ("cover", content_type, body))
        if not uploads:
            raise RenderError("send at least one media file or a cover_url")

        prune_jobs()
        job_id = uuid.uuid4().hex
        media_dir = job_dir(job_id) / "media"
        media_dir.mkdir(parents=True)
        media = []
        for index, (filename, content_type, body) in enumerate(uploads):
            kind = media_type(content_type, filename)
            if kind is None:
                shutil.rmtree(job_dir(job_id), ignore_errors=True)
                raise RenderError(f"{filename} is not an image or a video")
            name = f"{index:02d}{extension(content_type, filename)}"
            (media_dir / name).write_bytes(body)
            media.append({"file": f"http://127.0.0.1:{PORT}/v1/renders/{job_id}/media/{name}", "type": kind,
                          "durationInSeconds": video_seconds(media_dir / name) if kind == "video" else None})

        (job_dir(job_id) / "props.json").write_text(json.dumps({**props, "media": media}))
        write_job(job_id, id=job_id, status="queued", progress=0, error=None, created_at=time.time())
        self.jobs.put(job_id)
        return read_job(job_id) or {}

    def work(self) -> None:
        while True:
            job_id = self.jobs.get()
            try:
                self.render(job_id)
            except Exception as exc:  # noqa: BLE001 - surfaced through GET /v1/renders/{id}
                logging.exception("Render %s failed", job_id)
                write_job(job_id, status="failed", error=str(exc)[:500])

    def render(self, job_id: str) -> None:
        write_job(job_id, status="rendering", started_at=time.time())
        out = job_dir(job_id) / "video.mp4"
        command = [REMOTION, "render", "build", "Carousel", str(out),
                   f"--props={job_dir(job_id) / 'props.json'}", f"--concurrency={CONCURRENCY}"]
        process = subprocess.Popen(command, cwd=REMOTION_DIR, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        timer = threading.Timer(RENDER_TIMEOUT, process.kill)
        timer.start()
        tail: list[str] = []
        try:
            for line in process.stdout or []:
                tail = [*tail[-29:], line.rstrip()]
                # "Rendered 120/300" then "Encoded 300/300": rendering is ~90% of the time.
                if match := re.search(r"(Rendered|Encoded) (\d+)/(\d+)", line):
                    share = int(match.group(2)) / max(int(match.group(3)), 1)
                    write_job(job_id, progress=round(share * 0.9 if match.group(1) == "Rendered" else 0.9 + share * 0.1, 2))
            process.wait()
        finally:
            timer.cancel()
        if process.returncode != 0 or not out.exists():
            raise RenderError("\n".join(tail)[-500:] or f"remotion exited with {process.returncode}")
        write_job(job_id, status="done", progress=1, finished_at=time.time())


def recover_jobs() -> None:
    """Renders that were running when the service stopped are not resumed."""
    for path in DATA_DIR.iterdir():
        job = read_job(path.name)
        if job and job.get("status") in ("queued", "rendering"):
            write_job(path.name, status="failed", error="interrupted by a service restart")


def make_handler(renderer: Renderer):
    class Handler(BaseHTTPRequestHandler):
        def do_GET(self) -> None:  # noqa: N802
            path = self.path.split("?")[0]
            if path == "/health":
                return self._json(200, {"status": "ok"})
            if path == "/v1/templates/carousel":
                return self._json(200, {"data": json.loads((REMOTION_DIR / "src" / "defaults.json").read_text())})
            if match := re.fullmatch(rf"/v1/renders/({ID})", path):
                job = read_job(match.group(1))
                return self._json(200, {"data": job}) if job else self._json(404, {"error": "render not found"})
            if match := re.fullmatch(rf"/v1/renders/({ID})/video", path):
                return self._file(job_dir(match.group(1)) / "video.mp4", "video/mp4")
            if match := re.fullmatch(rf"/v1/renders/({ID})/media/(\d{{2}}\.?[a-z0-9]*)", path):
                file = job_dir(match.group(1)) / "media" / match.group(2)
                return self._file(file, mimetypes.guess_type(file.name)[0] or "application/octet-stream")
            self._json(404, {"error": "not found"})

        def do_POST(self) -> None:  # noqa: N802
            if self.path.split("?")[0] != "/v1/renders":
                return self._json(404, {"error": "not found"})
            length = int(self.headers.get("Content-Length") or 0)
            if length > MAX_UPLOAD_BYTES:
                return self._json(413, {"error": f"upload larger than {MAX_UPLOAD_BYTES // 1024 // 1024} MB"})
            try:
                fields, files = parse_multipart(self.headers.get("Content-Type", ""), self.rfile.read(length))
                self._json(202, {"data": renderer.create(fields, files)})
            except RenderError as exc:
                self._json(422, {"error": str(exc)})
            except Exception as exc:  # noqa: BLE001 - JSON error instead of a dropped connection
                logging.exception("Request failed: POST %s", self.path)
                self._json(500, {"error": str(exc)[:300]})

        def _json(self, status: int, payload: Any) -> None:
            body = json.dumps(payload).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def _file(self, path: Path, content_type: str) -> None:
            if not path.is_file():
                return self._json(404, {"error": "file not found"})
            self.send_response(200)
            self.send_header("Content-Type", content_type)
            self.send_header("Content-Length", str(path.stat().st_size))
            self.send_header("Access-Control-Allow-Origin", "*")
            self.end_headers()
            with path.open("rb") as file:
                shutil.copyfileobj(file, self.wfile)

        def log_message(self, fmt: str, *args: Any) -> None:
            logging.debug(fmt, *args)

    return Handler


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    recover_jobs()
    renderer = Renderer()
    threading.Thread(target=renderer.work, daemon=True).start()
    logging.info("pricebuddy-video listening on :%d", PORT)
    ThreadingHTTPServer(("0.0.0.0", PORT), make_handler(renderer)).serve_forever()


if __name__ == "__main__":
    main()
