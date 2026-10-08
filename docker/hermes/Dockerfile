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
        libx11-xcb1 \
        libxcomposite1 \
        libxcursor1 \
        libxdamage1 \
        libxext6 \
        libxfixes3 \
        libxi6 \
        libxrandr2 \
        libxrender1 \
        libxss1 \
        libxtst6 \
        libglib2.0-0 \
        libnss3 \
        libnspr4 \
        libdbus-1-3 \
        libatk1.0-0 \
        libatk-bridge2.0-0 \
        libcups2 \
        libdrm2 \
        libatspi2.0-0 \
        libx11-6 \
        libxext2 \
        libxrender1 \
        libxcb1 \
        libxcb-sync1 \
        libxcomposite1 \
        libxcursor1 \
        libxdamage1 \
        libxext6 \
        libxfixes3 \
        libxi6 \
        libxrandr2 \
        libxss1 \
        libxtst6 \
        fonts-liberation \
        fonts-noto-color-emoji \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid ${HERMES_GID} hermes \
    && useradd --uid ${HERMES_UID} --gid ${HERMES_GID} --create-home --shell /usr/sbin/nologin hermes \
    && mkdir -p /home/hermes/.mozilla/firefox/pricebuddy-hermes \
    && chown -R hermes:hermes /home/hermes \
    && mkdir -p /tmp/.X11-unix \
    && chmod 1777 /tmp/.X11-unix

ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

RUN pip install --no-cache-dir playwright==1.61.0 requests==2.32.3 invisible-playwright==0.27.0 twocaptcha \
    && playwright install --with-deps firefox \
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
    HERMES_ALLOWED_HOSTS= \
    HERMES_USE_CHROME=false

USER hermes

EXPOSE 8000

CMD ["bash", "run.sh"]
