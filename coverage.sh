#!/bin/bash

# Run the tests with coverage and generate a report
XDEBUG_MODE=coverage php artisan test --coverage-html tests/coverage