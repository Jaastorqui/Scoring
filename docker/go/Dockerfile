FROM golang:1.25-alpine

WORKDIR /app

# Copy go modules first (cache-friendly)
COPY go.mod go.sum ./
RUN go mod download

# Copy source
COPY main.go .

# Build Linux binary
RUN go build -o generator

CMD ["./generator"]
