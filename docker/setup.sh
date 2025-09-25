#!/bin/bash

# Define the base directory
BASE_DIR="docker/rabbitmq"
# Check if the base directory exists

if [ ! -d "$BASE_DIR" ]; then
    echo "Base directory '$BASE_DIR' does not exist. Creating it now..."
    mkdir -p "$BASE_DIR"
    echo "Directories created successfully."
else
    echo "Base directory '$BASE_DIR' already exists."
fi
# mkdir -p docker/rabbitmq
sudo chown -R $USER:$USER docker/rabbitmq/
sudo chmod -R 777 docker/rabbitmq/

docker compose up -d

sleep 5

sudo chown -R $USER:$USER src/
sudo chmod -R 777 src/

sleep 2

cp src/.env.example src/.env

docker exec stock-app composer install
sleep 2

sudo chmod -R 777 src/.env
docker exec stock-app php artisan key:generate
docker exec stock-app php artisan migrate:fresh --seed
