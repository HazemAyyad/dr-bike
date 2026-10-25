# Doctor Bike Hostinger routing

`public_html.htaccess` belongs at the domain's `public_html/.htaccess`.
It is separate from the Laravel checkout's `.htaccess` and `public/.htaccess`.
The checkout is deployed at `public_html/dr-bike`.

The file permits unencrypted HTTP only for GET/POST requests to:

- `/iclock/cdata`
- `/iclock/getrequest`
- `/iclock/devicecmd`

All other HTTP requests redirect to `https://dr-bike.shop` with status 307,
preserving the request method and body. HTTPS is still available everywhere.
The ADMS exception follows the original HTTP request line so Laravel's internal
rewrites do not accidentally redirect a device request. This exception does not
authenticate a device; existing application validation remains responsible for it.

## Deployment

1. Back up the live `public_html/.htaccess` outside the web root.
2. Deploy this file while Hostinger Force HTTPS is still enabled.
3. Check HTTPS website, API, and image responses for errors or redirect loops.
4. In hPanel, choose **SSL > Unforce HTTPS** for `dr-bike.shop`, keeping the SSL
   certificate installed. This removes the hosting redirect that runs before the
   application rules; the rules in this file then enforce HTTPS for other paths.
5. Confirm ADMS GET/POST requests over HTTP return their plain-text protocol
   responses without a redirect. Use requests without a device serial or scan
   payload for routing checks; do not create fake attendance records.
6. Confirm ordinary website/API requests and `/iclock/test` over HTTP still
   redirect to HTTPS, including POST requests. Verify the HTTPS site works.
7. Make a physical device scan and inspect the device's last contact, raw logs,
   receipt times, and attendance processing. Check historical upload separately;
   restoring connectivity does not prove the device resent older records.

If checks fail, restore the backup and re-enable Hostinger Force HTTPS.
Do not run migrations, seeders, attendance reprocessing, or a full deployment
script to make this routing change.

HTTP ADMS traffic, including employee identifiers and attendance times, is
unencrypted. This configuration is for devices that cannot use HTTPS and requires
the site owner's explicit agreement.
