<?php

/**
 * Filename: SystemWiki.php
 * Purpose: Provide system wiki processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Document leave ranges, approval tabs, role scopes and calendar birthdays.
 */

/** Product documentation: no personnel data, credentials or configuration values. */
class SystemWiki
{
    public static function Modules(): array
    {
        // key, sidebar group, title, purpose, steps, access / operating rules
        return [
            ['dashboard','Overview','Dashboard','Start the day with missing hours, returned logs, weekly approvals, section progress and renewals.',
                ['Review Hours to catch up, Returned weeks and your weekly entered-hour progress. Today becomes due at the configured reminder hour in Asia/Manila.','Open an employee week from Weekly approvals, or inspect section completion and renewals when your role allows them.','Use Search workspace (Ctrl / Cmd + K) to find modules and readable records. Select a result to open the record.'],
                'Actions follow your role and section scope. Submitted and approved work and leave count toward working days. Overtime on one date cannot cover a missing date. Private drafts stay private; Applications and Requirements remain hidden.'],
            ['notifications','Overview','Notifications','Follow daily-log digests, weekly approvals, review assignments and renewal reminders.',
                ['Open a notification to visit its related record.','Mark notices as read as you finish them.','Administrators configure reminders, email delivery and scheduled processing here.'],
                'Your inbox is personal. New daily-log and approval reminders default to in-app delivery at 17:00 Asia/Manila on working days; administrators can change the hour, lookback and approval delay. They exclude weekends, organization holidays and future days. One digest per type per person per day avoids duplicates. Approval reminders follow section reviewer assignments, with head self-review and administrator fallback. Resolved or outdated digests are marked read. Log-reminder email needs its separate opt-in, global email delivery and the recipient email preference.'],
            ['wiki','Help & updates','Documents Wiki','Find module instructions, the daily-log workflow and the system roadmap on a separate help page.',
                ['Open Help in the application top bar, then Documents Wiki. Search by module name, action or topic.','Use the contents to jump to a guide. Open module appears when your role allows that page.','Read the roadmap to distinguish delivered features from future work.'],
                'This is the built-in system guide. Documentation describes all modules; opening a module still requires its own permissions.'],
            ['updates','Help & updates',"What's New",'Read release notes and see how the system has changed over time.',
                ["Open Help in the top bar, then What's New.",'The update history opens in a modal. Read the newest release first, then browse earlier updates.','Choose Got it or close the modal after reading it. The dismissal is saved for your user account; the next release opens the modal again.'],
                'Available to every signed-in user. New releases open the modal automatically. Your acknowledgement is saved for your account, and earlier release notes remain in the history.'],
            ['applications','Workspace','Applications','Maintain application ownership and lifecycle records and connect related technical documentation.',
                ['Create an application with its owner and lifecycle details.','Link its servers, dictionary tables, requirements, diagrams, access links and code reviews.','Use the application record to follow its related information.'],
                'Temporarily hidden from navigation until further notice. Existing records and role permissions are retained.'],
            ['requirements','Workspace','Requirements','Track requirements and their related application, database and diagram records.',
                ['Record the requirement, description, priority and status.','Link supporting tables, columns, ERD entities or applications where relevant.','Filter and review traceability, then export Excel when allowed.'],
                'Temporarily hidden from navigation until further notice. Existing requirements, traceability links and role permissions are retained.'],
            ['datadict','Workspace','Data Dictionary','Document database tables and columns using metadata connections or manual records.',
                ['Configure an authorized database connection or add a table manually.','Generate metadata, then maintain business descriptions and column details.','Browse the dictionary and export documentation when allowed.'],
                'Connection details and exports are protected. Metadata access depends on configured database drivers and the source account permissions.'],
            ['diagrams','Workspace','ERD Generator','Build and maintain entity relationship diagrams from dictionary tables or custom entities.',
                ['Create a diagram and add dictionary tables or custom entities.','Arrange entities and add or adjust relationships.','Save the layout, then export using the available export controls.'],
                'Saving changes and exporting require the corresponding grants. Saved diagrams can be revisited from the dashboard.'],
            ['codereview','Workspace','Code Review','Run automated source checks and track review findings over time.',
                ['Choose the review engine and select a project folder on your computer or the server. Scans support up to 3,000 source files, 256 KB per file and 48 MB total.','Review findings, severity and suggested remediation; validate matches manually.','Open the saved review run, assign a reviewer, record dispositions or notes and compare runs. Export XLSX or ODS for the full report.'],
                'Only first-party source is reviewed. Installed vendor libraries, assets/plugins, node_modules, generated Tailwind output, runtime data and hidden folders are excluded. Finding displays remain capped at 5,000, and source inventory at 3,000 functions; limits are disclosed in the results. Saved review history excludes raw source. Automated and OWASP checks provide review prompts and do not establish that software is secure. Assignments and dispositions require review access.'],
            ['servers','Inventory','Server Inventory','Record server groups, hosted systems, connection details and renewal or expiry items.',
                ['Select a server group and maintain its servers and hosted systems.','Keep protocols, environment details and expiry items current.','Use permitted connection, credential and export actions as needed.'],
                'Viewing inventory does not grant permission to reveal credentials. Credential access and exports have separate grants.'],
            ['proginventory','Inventory','Program Inventory','Produce an Excel inventory of project files and their metadata.',
                ['Choose This computer to select a local folder, or Application server for a host path.','Enter project, version and developer details, then inspect the file metadata preview.','Review exclusions and use Scan & Export or the available export action.'],
                'Browser mode sends file metadata. Preview filters do not change the included files in the export. Export requires its own permission.'],
            ['directory','Inventory','Call Directory','Find staff contacts organized by department.',
                ['Search for a contact or browse departments.','Use the listed contact details to reach the right person.','Authorized maintainers can update contacts; organization structure is maintained in Organization.'],
                'The directory has public read-only access. Creating, editing and deleting contacts require a signed-in account with the relevant grants.'],
            ['bookmarks','Inventory','Access Links','Keep useful system URLs and quick links together.',
                ['Search or browse saved links.','Open a link to visit the related system.','Authorized maintainers can create, edit and remove links and their application associations.'],
                'Links have public read-only access. Maintenance requires the corresponding role permissions.'],
            ['teammonitoring','Team Monitoring','Team Monitoring','Track entered hours, missing hours and approved daily completion for section personnel.',
                ['Choose a week and filter by employee or authorized unit / section.','Read the entered hours and hours still needed below each employee.','Inspect weekday cells and open member logs to investigate missing or pending work.'],
                'Any role can be granted View. Non-administrators see their own section; administrators can monitor across sections. Entered hours count Submitted and Reviewed work and leave. Draft and Returned entries do not count. Remaining hours are calculated per working day: extra time on one date cannot cover another date. Approved completion is shown separately.'],
            ['leaves','Team Monitoring','Leaves','Apply for leave or undertime and view the department leave calendar.',
                ['Choose a month to see submitted and approved leave and birthdays across all sections in your department. Select a working date or Apply for Leave to start a request.','Set Date From and Date To and choose a leave type and period. Weekends and organization holidays are skipped; hours apply to each working day. Save a draft or submit the application. You can edit submitted requests until approval.','Authorized approvers use Approvals > Leave. One decision covers the entire date range; returning an application requires a comment. Section Heads may approve their own leave.'],
                'Calendar viewing is available to all existing roles and new roles by default. Calendar access follows your department; approval requires Leaves View and Review and is restricted to your own section. Grant Review to a chosen role in Roles & Permissions. Only Section Heads have the self-approval exception. Drafts are private to their applicant. Returned requests and application remarks are visible only to the applicant and authorized section approvers. Leave approval is independent of weekly work-log approval. Existing leave records and report totals are preserved.'],
            ['dailylogs','Team Monitoring','My Daily Logs','Record work activities, planned tasks and actual work.',
                ['Add a daily log and choose its date. Apply for leave or undertime in the separate Leaves module.','Select SD, SCR or Project and an activity type. Its full description appears below the selector in Add and Edit. Enter tasks and decimal hours.','Save a draft or submit for weekly review. Correct returned entries and resubmit them.'],
                'You can edit your own Draft, Submitted and Returned entries when your role allows editing. Submitted changes remain submitted and require reviewers to refresh the weekly snapshot before approving. Reviewed entries are locked; ask your reviewer to return the week for corrections. Only Draft and Returned entries can be deleted. Working days are Monday to Friday excluding organization holidays, with an 8-hour target. Leave is allowed on working days; AM/PM is 4 hours, whole day is 8, and undertime uses entered hours. Weekend or holiday work can be recorded but does not replace weekday hours.'],
            ['approvals','Team Monitoring','Approvals','Review work logs and leave applications in separate tabs within one approval module.',
                ['Choose the Daily Logs tab for weekly work approvals. Administrators assign the section reviewer using Section Reviewers.','Filter employee weeks, open View Week and inspect the submitted entries.','Approve the work week or return it with a comment; bulk decisions are available. The Leave tab requires Leaves View and Review, and approves or returns one complete leave range within your own section. Only Section Heads may approve their own leave.'],
                'Daily Logs decisions cover work entries from Monday to Sunday, excluding private drafts. Leave decisions are independent and application controls remain in the calendar. Reviewing other employees requires review permissions and a section reviewer assignment for non-administrators. Section Head and Department Head roles may approve or return their own week when Review Daily Logs View and Review are enabled; no section reviewer assignment is needed for self-review. Other roles, including Administrator, cannot approve their own week. New or corrected submissions reopen the full week for approval. An approved week can also be returned with a comment.'],
            ['reports','Team Monitoring','Reports','Export one section workbook with a separate employee worksheet, or inspect a personal weekly summary.',
                ['Choose Section Daily Logs / Weekly Summary, a section and a week, then preview personnel.','Export Excel for the activity summary by SD, SCR, Project and Total Hrs, with a separate leave area. Use Detailed Daily Entries for individual tasks, remarks and review details.','Use My Weekly Summary for your personal summary and its print / PDF controls.'],
                'The weekly activity summary counts approved Monday-to-Friday work; leave is listed separately. Each section employee gets a worksheet, including employees with no entries. Detailed exports exclude private drafts. Section reports require section access through Team Monitoring or reviewer assignment, plus Reports View; downloads also need Export. Sheet names use every first-name initial, the first middle-name initial and the full surname in uppercase: Michael Adrian Dimaano Monfero becomes MADMONFERO.'],
            ['holidays','Parameter','Holidays','Maintain the shared organization calendar used to calculate daily-log working-day targets.',
                ['Select a year to see its configured holiday dates.','Administrators enter a date and holiday name, or edit an existing holiday.','Refresh Team Monitoring after a calendar change to see recalculated required and remaining hours.'],
                'Every holiday applies to the whole organization. Dates are explicitly entered each year; none are automatically assumed. Weekends already have zero required hours. Calendar edits retain existing logs and weekly decisions. Holiday View can be granted to any role; only administrators can add, edit or delete dates.'],
            ['usermgmt','Parameter','Users','Manage accounts, roles, organization placement and sign-in status.',
                ['Search or filter accounts by status and department.','Create or edit an account and choose a valid organization chain and role. An optional Birthday appears on the department calendar each year without the birth year or age. Leap-day birthdays appear on February 28 in non-leap years.','Use permitted password reset, unlock and enable / disable actions when required.'],
                'Only administrators can change roles or manage administrator accounts. Keep at least one active administrator. Support previews require the separate Support grant.'],
            ['organization','Parameter','Organization','Maintain the organization hierarchy used by users, contacts and section monitoring.',
                ['Browse Group, Division, Department, Unit / Section and Position.','Select a node to inspect its details, then add or edit the appropriate level.','Reassign dependent users or records before deleting an empty node.'],
                'The parent-child chain must remain valid. A node with dependent children, contacts or users cannot be deleted.'],
            ['permissions','Parameter','Roles & Permissions','Control which modules and actions each role can use.',
                ['Select a preset role, or create a custom role with minimal access or a copied profile.','Review grants grouped in the same order as the sidebar. Enable View before other module actions.','Save the matrix, then assign the role in Users. Grant Team Monitoring View to any role that needs section visibility.'],
                'Role administration is restricted to administrators. Administrator access is protected. Custom roles can be renamed or deleted after assigned users are moved. Section Head and Department Head roles can approve their own employee week with View and Review grants. Approving other employees still requires a section reviewer assignment.'],
            ['settings','Parameter','Parameters','Configure password policy, email delivery and account recovery links.',
                ['Open the relevant Password Policy, Email Delivery or Recovery Links section.','Update and save that section; review its validation and saved-state indicator.','Use the saved SMTP settings for a test email before relying on email delivery.'],
                'Sections save independently. A blank SMTP password keeps the existing saved password. A Configured status indicates settings are present, not confirmed inbox delivery.'],
            ['logs','Logs','Error','Inspect application errors and diagnose failed operations.',
                ['Filter or search log entries to locate the relevant time and error.','Inspect the details needed to reproduce and investigate the issue.','Use permitted export or log-maintenance actions when necessary.'],
                'Access and maintenance are role-controlled. Error logs can contain operational details and should be shared only with appropriate maintainers.'],
            ['audit','Logs','Audit','Review recorded activity, authorization outcomes and data changes.',
                ['Filter events by date, actor, module or outcome.','Open an event to inspect the recorded request and related changes.','Export the filtered audit information when your role allows it.'],
                'Audit access and exports are separately protected. Audit records are evidence for investigation, not a replacement for backups. Sensitive values are excluded from retained metadata.'],
            ['account','Account','My Account','Review your profile and manage your password, avatar and appearance preferences.',
                ['Open the account menu in the sidebar footer.','Review your profile and update your avatar.','Change your password and use the available appearance preferences.'],
                'Personal account access is available to signed-in users. Roles, account status and organization placement are managed through Users.'],
        ];
    }

    public static function Roadmap(): array
    {
        return [
            ['Database Schema Comparison','Compare snapshots or live metadata to identify added, removed and changed objects.'],
            ['Approval Workflows','Extend approval beyond weekly daily logs to requirements, dictionary changes and important inventory updates.'],
            ['Document & Template Library','Versioned specifications, SOPs, deployment guides and templates linked to records. The Wiki currently provides the built-in system guide.'],
            ['Project Workspaces','Group requirements, diagrams, reviews, inventories, documents and tasks by project.'],
            ['Release & Deployment Register','Track versions, environments, deployment dates, approvals and rollback instructions.'],
            ['Dependency & Impact Map','Visualize relationships among applications, servers, tables and requirements.'],
            ['Scheduled Jobs & Health Monitor','Provide an operations dashboard for connectivity, email, job results and failed background tasks.'],
            ['Backup & Recovery Center','Create application backup packages and track restoration history.'],
            ['Report Builder','Build reusable cross-module reports beyond the existing daily-log and weekly-summary exports.'],
            ['Bulk Import with Preview','Preview validated Excel / CSV imports and duplicate detection before saving.'],
        ];
    }
}
