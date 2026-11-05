TrackED Orange Pi update
========================

What changed
------------
1. api/scan.php now uses Schedule Manager as the source of truth.
2. Scan stages supported:
   IN -> LUNCH_OUT -> LUNCH_IN -> OUT
3. Lunch break is optional per course/year schedule.
4. If a student never Lunches Out, Final OUT is still allowed.
5. If a student Lunches Out, Lunch In is required before Final OUT.
6. Guard verification remains required before any accepted event is committed.
7. Rejected scans save student_id + RFID + exact scan stage + reason.
8. When online, local rejected logs are opportunistically copied to the server
   if a matching server student RFID exists.
9. When an online attendance event is accepted, the current server attendance
   state is mirrored to tracked_local. This supports a mid-day server outage.
10. SMS is queued only for Time In and Final Time Out. Lunch Out/In do not send SMS.

Database migrations
-------------------
Run ORANGEPI_LOCAL_MIGRATION.sql on tracked_local.
Run SERVER_REQUIRED_MIGRATION.sql on tracked_db if those changes are not already applied.

Important sync note
-------------------
The uploaded ZIP did NOT contain /usr/local/bin/tracked_sync.py.
This package records lunch events into offline_queue and local attendance, but the
existing external sync worker has not been reviewed here. Export it before claiming
full offline lunch synchronization end-to-end:

  cp /usr/local/bin/tracked_sync.py /root/tracked_sync.py.backup

Then include that file in a future upload for review.

Recommended install
-------------------
1. Backup current web folder:
   cp -a /var/www/html/tracked /var/www/html/tracked_backup_before_lunch

2. Run local migration:
   mysql -u root -p tracked_local < /var/www/html/tracked/ORANGEPI_LOCAL_MIGRATION.sql

3. Validate scanner PHP:
   php -l /var/www/html/tracked/api/scan.php

4. Hard refresh Chromium kiosk (Ctrl+Shift+R), or restart kiosk browser using your
   existing autostart method.

Expected kiosk labels
---------------------
TIME IN
LUNCH OUT
LUNCH IN
FINAL TIME OUT

Denied logs
-----------
Online reject: written directly to tracked_db.rejected_scans, so Admin Dashboard can
show student identity using rejected_scans.student_id joined to students.id.
Offline reject: written to tracked_local.rejected_scans; next online scanner request
attempts to copy matching records to tracked_db.rejected_scans.
