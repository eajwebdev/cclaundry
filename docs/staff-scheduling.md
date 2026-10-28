Staff Scheduling lives at `/admin/scheduling`. The client reference is `public/Cane_Cotton_Scheduling_Screens_p4-7.pdf`, ignored by Git. The implementation covers the supplied scheduling screens (pages 4–7); other pages of the full specification were not supplied.

Deployment

- Run `php artisan migrate --force` and `npm run build`.
- Keep Laravel's scheduler running (`php artisan schedule:run` every minute). At 18:00 it adds tomorrow's in-app shift reminders. At 04:00 it closes and flags previous-day missing punches at their published shift end. Commands are repeatable: `php artisan scheduling:maintain` and `php artisan scheduling:maintain --remind`.
- Existing admins and branch managers who have Attendance access receive Scheduling access during migration. Other users can be granted the menu permission in Users. The owner is the existing `super_admin` role.
- Add active people in Employees. Scheduling uses those same employee accounts. Set scheduling role (all-around / part-time / rider); the owner can set an explicit daily rate. Existing real employee rates must be entered by the owner; no hourly-to-daily conversion is assumed.

Planning

Day, Week and Month use the same shift data. The week grid scrolls horizontally on phones. Click a cell to edit, drag a template onto a cell, select multiple cells before applying a template, or long-press a populated cell to copy it. Day view exposes draggable start/end handles and breaks, snapped to 15 minutes; dragging opens the editor for validation and saving. Drafts and published shifts have different colors.

Shifts are within one calendar day; overnight shifts are not supported by this reference. Paid hours deduct the configured unpaid break. Each scheduled working day costs one fixed daily rate, regardless of shift length. Rest days and leave have no scheduled work cost. Scheduling does not calculate overtime, add premiums for long shifts, or require overtime approval. Estimates and rates are only rendered for the owner. These are planning estimates, not a replacement for payroll; holidays flag premium-pay review rather than applying unprovided payroll rules.

Coverage is evaluated at 15-minute intervals, excluding breaks. Minimum shop staff and riders are separate settings. Publishing requires acknowledgement when coverage is short. Copy last week fills empty cells; Repeat for month fills unpublished draft weeks in the selected month, preserving the source week. Both skip official holiday dates, approved leave, and published shifts, and report skipped cells. The owner enters the annual holiday list rather than using the sample dates from the PDF.

Staff retain the last published snapshot while a manager drafts changes. Publish week stores the new snapshots, and creates in-app notifications only for affected employees. Clearing a cell is also a draft and takes effect for staff on publication. Staff can view/print their published week or month through My schedule in the existing time clock, request leave, and read notifications. Pending leave does not change shifts. Manager approval immediately replaces the affected days with published approved leave and prevents draft work shifts from overwriting that leave.

Attendance

The existing employee sign-in and camera-based time clock are retained; no separate shared PIN is introduced. A numeric employee password/PIN can be used through the existing sign-in flow. Admin time-in remains the manager override for early arrivals. Unscheduled employees retain existing attendance behavior; published rest/leave or an assigned branch is enforced when that employee has a published entry for the day.

Clock-in opens by default 15 minutes before the published start. Late arrivals use the configured grace (default five minutes). Clock-out records the actual time without overtime flags or approval. Scheduled unpaid breaks are deducted automatically (optional manual break punching is not enabled). Day view shows planned coverage, actual clock-in, lateness, scheduled break status, missing punches, hours worked so far.

Punches are first stored in IndexedDB with a photo, capture time, employee identity and unique event ID, then synced in order. The device must first have loaded the time clock online and signed in; this is not a standalone installable offline app. Pending punches survive a page reload and sync under the same employee account. Server event IDs prevent duplicates after lost responses. Device times up to 48 hours old are accepted and marked as device-synced for review; later or future timestamps require a manager correction. Rejected punches remain visible with a retry/discard action rather than silently disappearing. A delayed actual clock-out replaces a synthetic missing punch. Photos leave device storage after successful sync.

All draft changes, publication, copying, scheduling settings, and leave decisions produce activity-log records. Staff pages expose only the employee session's own published data and notices. Branch managers cannot modify another branch, and non-manager menu users cannot edit schedules.
