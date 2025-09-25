#!/bin/bash

docker compose -p flat build --no-cache

# Print a success message
echo "Docker image built successfully."