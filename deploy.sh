#!/bin/bash
docker login -u="$QUAY_USERNAME" -p="$QUAY_PASSWORD" quay.io
docker tag keboola/db-writer-mssql quay.io/keboola/db-writer-mssql:$TRAVIS_TAG
docker images
docker push quay.io/keboola/db-writer-mssql:$TRAVIS_TAG
