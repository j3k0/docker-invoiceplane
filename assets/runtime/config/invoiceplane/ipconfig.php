# <?php exit('No direct script access allowed'); ?>
# InvoicePlane Configuration File

# Environment (production, development, testing)
CI_ENV=production

# Set your URL without trailing slash here, e.g. http://your-domain.com
# If you use a subdomain, use http://subdomain.your-domain.com
# If you use a subfolder, use http://your-domain.com/subfolder
IP_URL=

# Having problems? Enable debug by changing the value to 'true' to enable advanced logging
ENABLE_DEBUG=false

# Set this setting to 'true' if you want to disable the setup for security purposes
DISABLE_SETUP=false

# To remove index.php from the URL, set this setting to 'true'.
# Please notice the additional instructions in the htaccess file!
REMOVE_INDEXPHP=false

# These database settings are set during the initial setup
DB_HOSTNAME=
DB_USERNAME=
DB_PASSWORD=
DB_DATABASE=
DB_PORT=

# If you want to be logged out after closing your browser window, set this setting to 0 (ZERO).
# The number represents the amount of minutes after that IP will automatically log out users,
# the default is 10 days.
SESS_EXPIRATION=864000

# Session: match IP address for session validation
SESS_MATCH_IP=true

# Session: regenerate session ID and destroy old session
SESS_REGENERATE_DESTROY=false

# Enable the deletion of invoices
ENABLE_INVOICE_DELETION=false

# Disable the read-only mode for invoices
DISABLE_READ_ONLY=false

# Use legacy calculation method (for backward compatibility with existing invoices)
LEGACY_CALCULATION=true

# Security: X-Frame-Options header (SAMEORIGIN, DENY, or ALLOW-FROM uri)
X_FRAME_OPTIONS=SAMEORIGIN

# Security: X-Content-Type-Options header
ENABLE_X_CONTENT_TYPE_OPTIONS=true

# Proxy IPs (comma-separated, used by CodeIgniter for trusted proxies)
PROXY_IPS=

# Password reset rate limiting
PASSWORD_RESET_IP_MAX_ATTEMPTS=5
PASSWORD_RESET_IP_WINDOW_MINUTES=60
PASSWORD_RESET_EMAIL_MAX_ATTEMPTS=3
PASSWORD_RESET_EMAIL_WINDOW_HOURS=1

# Sumex e-invoicing settings
SUMEX_SETTINGS=false
SUMEX_URL=

##
## DO NOT CHANGE ANY CONFIGURATION VALUES BELOW THIS LINE!
## =======================================================
##

# This key is automatically set after the first setup. Do not change it manually!
ENCRYPTION_KEY=
ENCRYPTION_CIPHER=AES-256

# Set to true after the initial setup
SETUP_COMPLETED=false
