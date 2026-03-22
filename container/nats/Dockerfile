FROM nats:2.12.5-alpine3.22

COPY docker-entrypoint.sh "/usr/local/bin/"
COPY nats-server.conf "/etc/nats/"

RUN apk add curl && \
    mkdir -p "/nats/storage" && \
    chmod -R 777 "/nats/storage"
