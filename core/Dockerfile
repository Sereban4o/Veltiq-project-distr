# Compiled "protected core" service: ships as a static binary without sources.
FROM golang:1.23-alpine AS build

WORKDIR /src

# Dependencies are stdlib-only, so no module download is needed.
COPY go.mod ./
COPY main.go ./
COPY internal ./internal

# Static build, no cgo: the result runs on a source-less base image.
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/core .

# Tests run in the build stage so a broken algorithm cannot be shipped.
RUN go vet ./... && go test ./...

FROM alpine:3.20

RUN adduser -D -H -u 10001 core

COPY --from=build /out/core /usr/local/bin/core

USER core
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/core"]
