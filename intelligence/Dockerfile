FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 TZ=America/Sao_Paulo
WORKDIR /opt/intelligence

COPY intelligence/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt && useradd --create-home intelligence \
    && mkdir -p /data && chown intelligence /data

COPY --chown=intelligence:intelligence intelligence/src ./src
COPY --chown=intelligence:intelligence intelligence/tests ./tests
USER intelligence
WORKDIR /opt/intelligence/src

EXPOSE 8100
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8100/health')"
CMD ["python", "server.py"]
