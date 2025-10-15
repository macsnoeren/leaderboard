#!/bin/sh

echo off
cd ../htdocs

echo "Create the artifact store"
mkdir artifacts
chmod 777 artifacts

cd ../
echo "Create the database"
mkdir database
touch database/leaderboard.db
chmod 777 database
chmod 666 database/leaderboard.db
