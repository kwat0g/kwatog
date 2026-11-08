FROM mcr.microsoft.com/playwright:v1.63.0-noble

USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-16 poppler-utils \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /work/spa
COPY spa/package.json spa/package-lock.json ./
RUN npm ci --no-audit --no-fund \
    && mkdir -p /home/e2e \
    && chown 1000:1000 /home/e2e

ENV HOME=/home/e2e
USER 1000:1000

CMD ["npx", "playwright", "test", "-c", "playwright.process.config.ts"]
