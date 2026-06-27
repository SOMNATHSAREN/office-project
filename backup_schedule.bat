@echo off
:: Office Project — Auto Backup
:: Schedule this file in Windows Task Scheduler to run daily/weekly.
::
:: To register with Task Scheduler (run as Administrator in PowerShell):
::   schtasks /create /tn "OfficeProjectBackup" /tr "C:\path\to\office_project\backup_schedule.bat" /sc daily /st 02:00 /ru SYSTEM /f
::
:: To delete the task:
::   schtasks /delete /tn "OfficeProjectBackup" /f

set PHP_EXE=C:\wamp64\bin\php\php8.2.28\php.exe
set SCRIPT=C:\Users\somsa\OneDrive\Documents\Claude_folder\office_project\backup_cli.php
set LOG=C:\Users\somsa\OneDrive\Documents\Claude_folder\office_project\backups\backup.log

"%PHP_EXE%" "%SCRIPT%" --keep=7 >> "%LOG%" 2>&1
