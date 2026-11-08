FROM node:20-alpine

WORKDIR /app
COPY spa/package.json spa/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY spa/ ./
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST=reverb
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_SCHEME=http
ENV VITE_DISABLE_API_PROXY=true \
    VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

RUN NODE_OPTIONS=--max-old-space-size=1536 npx vite build

EXPOSE 5173
CMD ["npm", "run", "preview", "--", "--host", "0.0.0.0", "--port", "5173", "--strictPort"]
