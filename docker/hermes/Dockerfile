FROM python:3.12-bookworm

ARG HERMES_UID=10001
ARG HERMES_GID=10001

RUN apt-get update \
    && apt-get install --no-install-recommends -y \
        ca-certificates \
        gnupg \
        novnc \
        websockify \
        wget \
        x11vnc \
        x11-utils \
        xvfb \
    && wget -qO- https://dl.google.com/linux/linux_signing_key.pub \
        | gpg --dearmor -o /usr/share/keyrings/google-chrome.gpg \
    && echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-chrome.gpg] https://dl.google.com/linux/chrome/deb/ stable main" \
        > /etc/apt/sources.list.d/google-chrome.list \
    && apt-get update \
    && apt-get install --no-install-recommends -y google-chrome-stable \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid ${HERMES_GID} hermes \
    && useradd --uid ${HERMES_UID} --gid ${HERMES_GID} --create-home --shell /usr/sbin/nologin hermes \
    && mkdir -p /home/hermes/.config/chromium \
    && chown -R hermes:hermes /home/hermes \
    && mkdir -p /tmp/.X11-unix \
    && chmod 1777 /tmp/.X11-unix

ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

RUN pip install --no-cache-dir playwright==1.61.0 requests==2.32.3 \
    && playwright install --with-deps chromium \
    && chown -R hermes:hermes /ms-playwright

WORKDIR /opt/hermes

COPY --chown=hermes:hermes hermes/src ./src
COPY --chown=hermes:hermes hermes/tests ./tests
COPY --chown=hermes:hermes docker/hermes/run-visual-diagnose.sh ./run-visual-diagnose.sh
COPY --chown=hermes:hermes docker/hermes/run.sh ./run.sh
COPY --chown=hermes:hermes docker/hermes/run-agent.sh ./run-agent.sh
COPY --chown=hermes:hermes docker/hermes/run-agent-headless.sh ./run-agent-headless.sh

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    HERMES_HOST=0.0.0.0 \
    HERMES_PORT=8000 \
    PRICEBUDDY_API_BASE_URL=http://app/api \
    HERMES_ALLOWED_HOSTS=

USER hermes

EXPOSE 8000

CMD ["bash", "run.sh"]
