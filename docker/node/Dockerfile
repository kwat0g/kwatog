FROM node:20-alpine

RUN apk add --no-cache bash git

WORKDIR /app

EXPOSE 5173
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
