<?php
/**
 * Filename: Routes.php
 * Purpose: Declare every permitted web endpoint; automatic routing is disabled.
 * Developer: DevAtlas maintainers
 * Date: 2026-11-12
 * Recent updates: Register the account preference API alongside existing endpoints.
 */

$routes->setAutoRoute(false);
$routes->get('/', 'HomeController::Index');
$routes->match(['GET', 'POST'], 'email-details', 'AuthenticationController::AuthEmailPreview');
$routes->match(['GET', 'POST'], 'auth_email_preview.php', 'AuthenticationController::AuthEmailPreview');
$routes->match(['GET', 'POST'], 'change-password', 'AuthenticationController::ChangePassword');
$routes->match(['GET', 'POST'], 'change_password.php', 'AuthenticationController::ChangePassword');
$routes->match(['GET', 'POST'], 'forgot-password', 'AuthenticationController::ForgotPassword');
$routes->match(['GET', 'POST'], 'forgot_password.php', 'AuthenticationController::ForgotPassword');
$routes->match(['GET', 'POST'], 'index.php', 'HomeController::Index');
$routes->match(['GET', 'POST'], 'login', 'AuthenticationController::Login');
$routes->match(['GET', 'POST'], 'login.php', 'AuthenticationController::Login');
$routes->match(['GET', 'POST'], 'logout', 'AuthenticationController::Logout');
$routes->match(['GET', 'POST'], 'logout.php', 'AuthenticationController::Logout');
$routes->match(['GET', 'POST'], 'reset-password', 'AuthenticationController::ResetPassword');
$routes->match(['GET', 'POST'], 'reset_password.php', 'AuthenticationController::ResetPassword');
$routes->match(['GET', 'POST'], 'modules/account/search', 'AccountController::Search');
$routes->match(['GET', 'POST'], 'modules/account/search.php', 'AccountController::Search');
$routes->match(['GET', 'POST'], 'modules/account/upload-avatar', 'AccountController::UploadAvatar');
$routes->match(['GET', 'POST'], 'modules/account/upload_avatar.php', 'AccountController::UploadAvatar');
$routes->match(['GET', 'POST'], 'modules/applications/api', 'ApplicationsController::Api');
$routes->match(['GET', 'POST'], 'modules/applications/api.php', 'ApplicationsController::Api');
$routes->match(['GET', 'POST'], 'modules/applications', 'ApplicationsController::Index');
$routes->match(['GET', 'POST'], 'modules/applications/index.php', 'ApplicationsController::Index');
$routes->match(['GET', 'POST'], 'modules/audit/api', 'AuditController::Api');
$routes->match(['GET', 'POST'], 'modules/audit/api.php', 'AuditController::Api');
$routes->match(['GET', 'POST'], 'modules/audit/client', 'AuditController::Client');
$routes->match(['GET', 'POST'], 'modules/audit/client.php', 'AuditController::Client');
$routes->match(['GET', 'POST'], 'modules/audit/export', 'AuditController::Export');
$routes->match(['GET', 'POST'], 'modules/audit/export.php', 'AuditController::Export');
$routes->match(['GET', 'POST'], 'modules/audit', 'AuditController::Index');
$routes->match(['GET', 'POST'], 'modules/audit/index.php', 'AuditController::Index');
$routes->match(['GET', 'POST'], 'modules/bookmarks/api', 'BookmarksController::Api');
$routes->match(['GET', 'POST'], 'modules/bookmarks/api.php', 'BookmarksController::Api');
$routes->match(['GET', 'POST'], 'modules/bookmarks', 'BookmarksController::Index');
$routes->match(['GET', 'POST'], 'modules/bookmarks/index.php', 'BookmarksController::Index');
$routes->match(['GET', 'POST'], 'modules/codereview/api', 'CodereviewController::Api');
$routes->match(['GET', 'POST'], 'modules/codereview/api.php', 'CodereviewController::Api');
$routes->match(['GET', 'POST'], 'modules/codereview/export', 'CodereviewController::Export');
$routes->match(['GET', 'POST'], 'modules/codereview/export.php', 'CodereviewController::Export');
$routes->match(['GET', 'POST'], 'modules/codereview/history', 'CodereviewController::History');
$routes->match(['GET', 'POST'], 'modules/codereview/history.php', 'CodereviewController::History');
$routes->match(['GET', 'POST'], 'modules/codereview/history-api', 'CodereviewController::HistoryApi');
$routes->match(['GET', 'POST'], 'modules/codereview/history_api.php', 'CodereviewController::HistoryApi');
$routes->match(['GET', 'POST'], 'modules/codereview/history-export', 'CodereviewController::HistoryExport');
$routes->match(['GET', 'POST'], 'modules/codereview/history_export.php', 'CodereviewController::HistoryExport');
$routes->match(['GET', 'POST'], 'modules/codereview', 'CodereviewController::Index');
$routes->match(['GET', 'POST'], 'modules/codereview/index.php', 'CodereviewController::Index');
$routes->match(['GET', 'POST'], 'modules/dailylogs/api', 'DailylogsController::Api');
$routes->match(['GET', 'POST'], 'modules/dailylogs/api.php', 'DailylogsController::Api');
$routes->match(['GET', 'POST'], 'modules/dailylogs', 'DailylogsController::Index');
$routes->match(['GET', 'POST'], 'modules/dailylogs/index.php', 'DailylogsController::Index');
$routes->match(['GET', 'POST'], 'modules/datadict/api', 'DatadictController::Api');
$routes->match(['GET', 'POST'], 'modules/datadict/api.php', 'DatadictController::Api');
$routes->match(['GET', 'POST'], 'modules/datadict/excel-export', 'DatadictController::ExcelExport');
$routes->match(['GET', 'POST'], 'modules/datadict/excel_export.php', 'DatadictController::ExcelExport');
$routes->match(['GET', 'POST'], 'modules/datadict', 'DatadictController::Index');
$routes->match(['GET', 'POST'], 'modules/datadict/index.php', 'DatadictController::Index');
$routes->match(['GET', 'POST'], 'modules/diagrams/api', 'DiagramsController::Api');
$routes->match(['GET', 'POST'], 'modules/diagrams/api.php', 'DiagramsController::Api');
$routes->match(['GET', 'POST'], 'modules/diagrams/excel-export', 'DiagramsController::ExcelExport');
$routes->match(['GET', 'POST'], 'modules/diagrams/excel_export.php', 'DiagramsController::ExcelExport');
$routes->match(['GET', 'POST'], 'modules/diagrams/excel-import', 'DiagramsController::ExcelImport');
$routes->match(['GET', 'POST'], 'modules/diagrams/excel_import.php', 'DiagramsController::ExcelImport');
$routes->match(['GET', 'POST'], 'modules/diagrams/graphml-export', 'DiagramsController::GraphmlExport');
$routes->match(['GET', 'POST'], 'modules/diagrams/graphml_export.php', 'DiagramsController::GraphmlExport');
$routes->match(['GET', 'POST'], 'modules/diagrams', 'DiagramsController::Index');
$routes->match(['GET', 'POST'], 'modules/diagrams/index.php', 'DiagramsController::Index');
$routes->match(['GET', 'POST'], 'modules/directory/api', 'DirectoryController::Api');
$routes->match(['GET', 'POST'], 'modules/directory/api.php', 'DirectoryController::Api');
$routes->match(['GET', 'POST'], 'modules/directory', 'DirectoryController::Index');
$routes->match(['GET', 'POST'], 'modules/directory/index.php', 'DirectoryController::Index');
$routes->match(['GET', 'POST'], 'modules/holidays/api', 'HolidaysController::Api');
$routes->match(['GET', 'POST'], 'modules/holidays/api.php', 'HolidaysController::Api');
$routes->match(['GET', 'POST'], 'modules/holidays', 'HolidaysController::Index');
$routes->match(['GET', 'POST'], 'modules/holidays/index.php', 'HolidaysController::Index');
$routes->match(['GET', 'POST'], 'modules/leaves/api', 'LeavesController::Api');
$routes->match(['GET', 'POST'], 'modules/leaves/api.php', 'LeavesController::Api');
$routes->match(['GET', 'POST'], 'modules/leaves', 'LeavesController::Index');
$routes->match(['GET', 'POST'], 'modules/leaves/index.php', 'LeavesController::Index');
$routes->match(['GET', 'POST'], 'modules/logs/api', 'LogsController::Api');
$routes->match(['GET', 'POST'], 'modules/logs/api.php', 'LogsController::Api');
$routes->match(['GET', 'POST'], 'modules/logs/download', 'LogsController::Download');
$routes->match(['GET', 'POST'], 'modules/logs/download.php', 'LogsController::Download');
$routes->match(['GET', 'POST'], 'modules/logs', 'LogsController::Index');
$routes->match(['GET', 'POST'], 'modules/logs/index.php', 'LogsController::Index');
$routes->match(['GET', 'POST'], 'modules/notifications/api', 'NotificationsController::Api');
$routes->match(['GET', 'POST'], 'modules/notifications/api.php', 'NotificationsController::Api');
$routes->match(['GET', 'POST'], 'modules/notifications', 'NotificationsController::Index');
$routes->match(['GET', 'POST'], 'modules/notifications/index.php', 'NotificationsController::Index');
$routes->match(['GET', 'POST'], 'modules/organization/api', 'OrganizationController::Api');
$routes->match(['GET', 'POST'], 'modules/organization/api.php', 'OrganizationController::Api');
$routes->match(['GET', 'POST'], 'modules/organization', 'OrganizationController::Index');
$routes->match(['GET', 'POST'], 'modules/organization/index.php', 'OrganizationController::Index');
$routes->match(['GET', 'POST'], 'modules/permissions/api', 'PermissionsController::Api');
$routes->match(['GET', 'POST'], 'modules/permissions/api.php', 'PermissionsController::Api');
$routes->match(['GET', 'POST'], 'modules/permissions', 'PermissionsController::Index');
$routes->match(['GET', 'POST'], 'modules/permissions/index.php', 'PermissionsController::Index');
$routes->match(['GET', 'POST'], 'modules/proginventory/export', 'ProginventoryController::Export');
$routes->match(['GET', 'POST'], 'modules/proginventory/export.php', 'ProginventoryController::Export');
$routes->match(['GET', 'POST'], 'modules/proginventory/export-client', 'ProginventoryController::ExportClient');
$routes->match(['GET', 'POST'], 'modules/proginventory/export_client.php', 'ProginventoryController::ExportClient');
$routes->match(['GET', 'POST'], 'modules/proginventory', 'ProginventoryController::Index');
$routes->match(['GET', 'POST'], 'modules/proginventory/index.php', 'ProginventoryController::Index');
$routes->match(['GET', 'POST'], 'modules/reports/api', 'ReportsController::Api');
$routes->match(['GET', 'POST'], 'modules/reports/api.php', 'ReportsController::Api');
$routes->match(['GET', 'POST'], 'modules/reports/export', 'ReportsController::Export');
$routes->match(['GET', 'POST'], 'modules/reports/export.php', 'ReportsController::Export');
$routes->match(['GET', 'POST'], 'modules/reports', 'ReportsController::Index');
$routes->match(['GET', 'POST'], 'modules/reports/index.php', 'ReportsController::Index');
$routes->match(['GET', 'POST'], 'modules/requirements/api', 'RequirementsController::Api');
$routes->match(['GET', 'POST'], 'modules/requirements/api.php', 'RequirementsController::Api');
$routes->match(['GET', 'POST'], 'modules/requirements/excel-export', 'RequirementsController::ExcelExport');
$routes->match(['GET', 'POST'], 'modules/requirements/excel_export.php', 'RequirementsController::ExcelExport');
$routes->match(['GET', 'POST'], 'modules/requirements', 'RequirementsController::Index');
$routes->match(['GET', 'POST'], 'modules/requirements/index.php', 'RequirementsController::Index');
$routes->match(['GET', 'POST'], 'modules/reviewlogs/api', 'ReviewlogsController::Api');
$routes->match(['GET', 'POST'], 'modules/reviewlogs/api.php', 'ReviewlogsController::Api');
$routes->match(['GET', 'POST'], 'modules/reviewlogs', 'ReviewlogsController::Index');
$routes->match(['GET', 'POST'], 'modules/reviewlogs/index.php', 'ReviewlogsController::Index');
$routes->match(['GET', 'POST'], 'modules/servers/api', 'ServersController::Api');
$routes->match(['GET', 'POST'], 'modules/servers/api.php', 'ServersController::Api');
$routes->match(['GET', 'POST'], 'modules/servers', 'ServersController::Index');
$routes->match(['GET', 'POST'], 'modules/servers/index.php', 'ServersController::Index');
$routes->match(['GET', 'POST'], 'modules/servers/rdp-download', 'ServersController::RdpDownload');
$routes->match(['GET', 'POST'], 'modules/servers/rdp_download.php', 'ServersController::RdpDownload');
$routes->match(['GET', 'POST'], 'modules/settings/api', 'SettingsController::Api');
$routes->match(['GET', 'POST'], 'modules/settings/api.php', 'SettingsController::Api');
$routes->match(['GET', 'POST'], 'modules/settings', 'SettingsController::Index');
$routes->match(['GET', 'POST'], 'modules/settings/index.php', 'SettingsController::Index');
$routes->match(['GET', 'POST'], 'modules/teammonitoring/api', 'TeammonitoringController::Api');
$routes->match(['GET', 'POST'], 'modules/teammonitoring/api.php', 'TeammonitoringController::Api');
$routes->match(['GET', 'POST'], 'modules/teammonitoring', 'TeammonitoringController::Index');
$routes->match(['GET', 'POST'], 'modules/teammonitoring/index.php', 'TeammonitoringController::Index');
$routes->match(['GET', 'POST'], 'modules/updates/api', 'UpdatesController::Api');
$routes->match(['GET', 'POST'], 'modules/updates/api.php', 'UpdatesController::Api');
$routes->match(['GET', 'POST'], 'modules/usermgmt/api', 'UsermgmtController::Api');
$routes->match(['GET', 'POST'], 'modules/usermgmt/api.php', 'UsermgmtController::Api');
$routes->match(['GET', 'POST'], 'modules/usermgmt', 'UsermgmtController::Index');
$routes->match(['GET', 'POST'], 'modules/usermgmt/index.php', 'UsermgmtController::Index');
$routes->match(['GET', 'POST'], 'modules/wiki', 'WikiController::Index');
$routes->match(['GET', 'POST'], 'modules/wiki/index.php', 'WikiController::Index');
$routes->match(['GET', 'POST'], 'modules/approvals', 'ApprovalsController::Index');
$routes->match(['GET', 'POST'], 'modules/approvals/index.php', 'ApprovalsController::Index');


// Data Management / Data Conversion (clean and legacy URLs).
$routes->match(['GET', 'POST'], 'modules/dataconversion', 'DataconversionController::Index');
$routes->match(['GET', 'POST'], 'modules/dataconversion/index.php', 'DataconversionController::Index');
$routes->match(['GET', 'POST'], 'modules/dataconversion/api', 'DataconversionController::Api');
$routes->match(['GET', 'POST'], 'modules/dataconversion/api.php', 'DataconversionController::Api');


// Account-owned interface preferences.
$routes->match(['GET', 'POST'], 'modules/account/ui-preferences', 'AccountController::UiPreferences');
$routes->match(['GET', 'POST'], 'modules/account/ui_preferences.php', 'AccountController::UiPreferences');

$routes->match(['GET', 'POST'], 'modules/account/experience', 'AccountController::Experience');
$routes->match(['GET', 'POST'], 'modules/account/experience.php', 'AccountController::Experience');
