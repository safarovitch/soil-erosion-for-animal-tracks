#!/bin/bash
# Script to clean up idle PostgreSQL connections
# This should be run periodically via cron (e.g., every 5 minutes)

# Load environment variables from .env file
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
ENV_FILE="${PROJECT_ROOT}/.env"

if [ -f "$ENV_FILE" ]; then
    # Source .env file and extract database credentials
    export $(grep -v '^#' "$ENV_FILE" | grep -E '^DB_' | xargs)
fi

# Use environment variables or defaults
DB_USER="${DB_USERNAME:-rusle_user}"
DB_NAME="${DB_DATABASE:-rusle_icarda}"
IDLE_TIMEOUT_SECONDS=30  # Very aggressive - kill connections idle for 30 seconds
AGGRESSIVE_THRESHOLD=5   # If more than 5 idle connections, kill all idle immediately

# Check if PostgreSQL is accessible
if ! sudo -u postgres psql -lqt | cut -d \| -f 1 | grep -qw "$DB_NAME"; then
    echo "$(date): ERROR - Database '$DB_NAME' not found" >> /var/log/postgres-connection-cleanup.log 2>&1
    exit 1
fi

# Count connections before cleanup
BEFORE_COUNT=$(sudo -u postgres psql -d "$DB_NAME" -t -c "SELECT count(*) FROM pg_stat_activity WHERE datname = '$DB_NAME' AND usename = '$DB_USER' AND state = 'idle' AND pid != pg_backend_pid();" 2>/dev/null | xargs)

# Terminate idle connections - more aggressive approach
TOTAL_IDLE=$(sudo -u postgres psql -d "$DB_NAME" -t -c "
SELECT count(*) 
FROM pg_stat_activity 
WHERE datname = '$DB_NAME' 
  AND usename = '$DB_USER' 
  AND state = 'idle' 
  AND pid != pg_backend_pid();
" 2>/dev/null | xargs)

TERMINATED=0
if [ "$TOTAL_IDLE" -gt "$AGGRESSIVE_THRESHOLD" ]; then
    # If more than threshold idle connections, kill ALL idle connections immediately
    TERMINATED="$TOTAL_IDLE"
    
    sudo -u postgres psql -d "$DB_NAME" <<EOF 2>/dev/null
SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE datname = '$DB_NAME'
  AND usename = '$DB_USER'
  AND state = 'idle'
  AND pid != pg_backend_pid();
EOF
else
    # Normal cleanup: kill connections idle for more than timeout
    TERMINATED=$(sudo -u postgres psql -d "$DB_NAME" -t -c "
    SELECT count(*) 
    FROM pg_stat_activity 
    WHERE datname = '$DB_NAME' 
      AND usename = '$DB_USER' 
      AND state = 'idle' 
      AND state_change < now() - interval '${IDLE_TIMEOUT_SECONDS} seconds'
      AND pid != pg_backend_pid();
    " 2>/dev/null | xargs)
    
    if [ "$TERMINATED" -gt 0 ]; then
        sudo -u postgres psql -d "$DB_NAME" <<EOF 2>/dev/null
SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE datname = '$DB_NAME'
  AND usename = '$DB_USER'
  AND state = 'idle'
  AND state_change < now() - interval '${IDLE_TIMEOUT_SECONDS} seconds'
  AND pid != pg_backend_pid();
EOF
    fi
fi

# Count connections after cleanup
AFTER_COUNT=$(sudo -u postgres psql -d "$DB_NAME" -t -c "SELECT count(*) FROM pg_stat_activity WHERE datname = '$DB_NAME' AND usename = '$DB_USER' AND state = 'idle' AND pid != pg_backend_pid();" 2>/dev/null | xargs)

# Log the cleanup
echo "$(date): Cleaned up $TERMINATED idle connections (before: $BEFORE_COUNT, after: $AFTER_COUNT)" >> /var/log/postgres-connection-cleanup.log 2>&1
