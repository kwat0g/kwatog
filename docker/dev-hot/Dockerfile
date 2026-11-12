FROM node:20-alpine

WORKDIR /app
COPY spa/package.json spa/package-lock.json ./
RUN npm ci --no-audit --no-fund && chown -R node:node /app
USER node

EXPOSE 5173
