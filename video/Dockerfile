# pricebuddy-video: Remotion templates + a small Python HTTP API that renders them.
FROM node:22-bookworm-slim

ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 TZ=America/Sao_Paulo

# Python for the API; libraries of Chrome Headless Shell (remotion.dev/docs/miscellaneous/linux-dependencies); fonts.
RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 ca-certificates fonts-noto-core fonts-noto-color-emoji \
        libnss3 libdbus-1-3 libatk1.0-0 libgbm-dev libasound2 libxrandr2 libxkbcommon-dev libxfixes3 \
        libxcomposite1 libxdamage1 libatk-bridge2.0-0 libpango-1.0-0 libcairo2 libcups2 \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home remotion && mkdir -p /data && chown remotion /data

WORKDIR /opt/video/remotion
COPY video/remotion/package.json video/remotion/package-lock.json ./
RUN npm ci --no-audit --no-fund && npx remotion browser ensure

COPY video/remotion ./
RUN npx remotion bundle src/index.ts --out-dir=build && chown -R remotion:remotion /opt/video
COPY --chown=remotion:remotion video/server.py /opt/video/server.py

USER remotion
EXPOSE 8200
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD python3 -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8200/health')"
CMD ["python3", "/opt/video/server.py"]
