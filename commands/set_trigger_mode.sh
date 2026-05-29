#!/bin/bash
# $1 = mode (always_on, show_active, command, motion_sensor)
curl -s -X POST http://localhost:5001/api/config \
  -H 'Content-Type: application/json' \
  -d "{\"trigger_mode\":\"$1\"}"
