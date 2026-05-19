<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

$routes->get('/', 'Home::index');

// ========================================
// DEBUG TEMPORAIRE — À SUPPRIMER APRÈS
// ========================================
$routes->get('api/debug', function() {
    // Afficher TOUTES les variables d'environnement disponibles
    $allEnv = getenv();

    // Filtrer uniquement celles qui contiennent "DB" ou "database"
    $dbVars = [];
    foreach ($allEnv as $key => $value) {
        if (stripos($key, 'DB') !== false || stripos($key, 'database') !== false) {
            $dbVars[$key] = (stripos($key, 'PASS') !== false || stripos($key, 'PASSWORD') !== false)
                            ? '***masqué***'
                            : $value;
        }
    }

    try {
        $db = \Config\Database::connect();
        $db->query('SELECT 1');
        $dbStatus = '✅ Connexion OK';
    } catch (\Throwable $e) {
        $dbStatus = '❌ ' . $e->getMessage();
    }

    return \Config\Services::response()->setJSON([
        'vars_trouvées' => $dbVars,
        'count'         => count($dbVars),
        'DB_HOSTNAME'   => getenv('DB_HOSTNAME') ?: 'NON DÉFINI',
        'DB_PORT'       => getenv('DB_PORT')     ?: 'NON DÉFINI',
        'DB_USERNAME'   => getenv('DB_USERNAME') ?: 'NON DÉFINI',
        'DB_DATABASE'   => getenv('DB_DATABASE') ?: 'NON DÉFINI',
        'db_status'     => $dbStatus,
    ]);
});

$routes->group('api', function ($routes) {

    // ========================================
    // INSCRIPTION CLIENT
    // ========================================
    $routes->post('register/send-email-code',   'RegisterController::sendEmailCode');
    $routes->post('register/verify-email-code', 'RegisterController::verifyEmailCode');
    $routes->post('register/resend-email-code', 'RegisterController::resendEmailCode');
    $routes->post('register/contact',           'RegisterController::registerContact');

    // ========================================
    // CONNEXION (client + staff — login unifié)
    // ========================================
    $routes->post('login',         'LoginController::login');
    $routes->get('user',           'LoginController::getUser');
    $routes->post('refresh-token', 'LoginController::refreshToken');

    // ========================================
    // DÉCONNEXION
    // ========================================
    $routes->post('logout', 'LogoutController::logout');

    // ========================================
    // RÉINITIALISATION DE MOT DE PASSE
    // ========================================
    $routes->post('password/request-reset', 'PasswordResetController::requestReset');
    $routes->post('password/verify-code',   'PasswordResetController::verifyResetCode');
    $routes->post('password/reset',         'PasswordResetController::resetPassword');

    // ========================================
    // PROFIL CLIENT
    // ========================================
    $routes->get( 'profile',                  'ProfileController::getProfile');
    $routes->post('profile/update',           'ProfileController::updateContact');
    $routes->post('profile/update-company',   'ProfileController::updateCompany');
    $routes->get( 'profile/countries',        'ProfileController::getCountries');
    $routes->post('profile/upload-image',     'ProfileController::uploadImage');
    $routes->post('profile/change-password',  'ProfileController::changePassword');

    // ========================================
    // STAFF
    // ========================================
    $routes->post('staff/register',       'StaffController::register');
    $routes->post('staff/verify-otp',     'StaffController::verifyOtp');
    $routes->post('staff/resend-otp',     'StaffController::resendOtp');
    $routes->post('staff/update-profile', 'StaffController::updateProfile');
    $routes->get('staff/profile',         'StaffController::profile');

    // ========================================
    // PROPOSALS
    // ========================================
    $routes->get(   'proposals/list',              'ProposalController::list');
    $routes->get(   'proposals/detail/(:num)',     'ProposalController::detail/$1');
    $routes->post(  'proposals/create',            'ProposalController::create');
    $routes->post(  'proposals/update/(:num)',     'ProposalController::update/$1');
    $routes->post(  'proposals/change-status',     'ProposalController::changeStatus');
    $routes->post(  'proposals/send-email/(:num)', 'ProposalController::sendEmail/$1');
    $routes->get(   'proposals/pdf/(:num)',        'ProposalController::pdf/$1');
    $routes->post(  'proposals/convert/(:num)',    'ProposalController::convert/$1');
    $routes->delete('proposals/delete/(:num)',     'ProposalController::delete/$1');
    $routes->get( 'proposals/(:num)/tasks',        'ProposalController::tasks/$1');
    $routes->get( 'proposals/(:num)/reminders',    'ProposalController::reminders/$1');
    $routes->post('proposals/(:num)/reminders',    'ProposalController::addReminder/$1');
    $routes->get('proposals/clients',      'ProposalController::clients');
    $routes->get('proposals/contacts',     'ProposalController::contacts');
    $routes->get('proposals/taxes',        'ProposalController::taxes');
    $routes->get('proposals/currencies',   'ProposalController::currencies');
    $routes->get('proposals/staff-list',   'ProposalController::staffList');
    $routes->get('proposals/countries',    'ProposalController::countries');
    $routes->get('proposals/next-number',  'ProposalController::nextNumber');
    $routes->get( 'proposals/client-list',            'ProposalController::clientList');
    $routes->get( 'proposals/client-detail/(:num)',   'ProposalController::clientDetail/$1');
    $routes->get( 'proposals/client-pdf/(:num)',      'ProposalController::clientPdf/$1');
    $routes->post('proposals/client-respond/(:num)',  'ProposalController::clientRespond/$1');

    // ========================================
    // TICKETS
    // ========================================
    $routes->get(   'tickets/departments',              'TicketController::getDepartments');
    $routes->get(   'tickets/priorities',               'TicketController::getPriorities');
    $routes->get(   'tickets/statuses',                 'TicketController::getStatuses');
    $routes->post(  'tickets/create',                   'TicketController::create');
    $routes->put(   'tickets/update/(:num)',             'TicketController::update/$1');
    $routes->delete('tickets/delete/(:num)',             'TicketController::delete/$1');
    $routes->get(   'tickets/list',                     'TicketController::clientList');
    $routes->get(   'tickets/all',                      'TicketController::staffList');
    $routes->get(   'tickets/detail/(:num)',             'TicketController::detail/$1');
    $routes->post(  'tickets/reply',                    'TicketController::reply');
    $routes->post(  'tickets/change-status',            'TicketController::changeStatus');
    $routes->get(   'tickets/attachment/(:num)/(:any)', 'TicketController::serveAttachment/$1/$2');
    $routes->post(  'tickets/upload',                   'TicketController::uploadAttachment');
    $routes->get(   'tickets/stats',                    'TicketController::stats');
    $routes->get(   'clients/contacts',                 'TicketController::getContacts');

    // ========================================
    // DEPARTEMENTS
    // ========================================
    $routes->get(   'tickets/departments/all',           'TicketController::getAllDepartments');
    $routes->post(  'tickets/departments/create',        'TicketController::createDepartment');
    $routes->post('tickets/departments/update/(:num)', 'TicketController::updateDepartment/$1');
    $routes->post('tickets/departments/delete/(:num)', 'TicketController::deleteDepartment/$1');

    // ========================================
    // ESTIMATES (DEVIS)
    // ========================================
    $routes->get(   'estimates/list',                  'EstimateController::list');
    $routes->get(   'estimates/next-number',           'EstimateController::nextNumber');
    $routes->get(   'estimates/detail/(:num)',         'EstimateController::detail/$1');
    $routes->get(   'estimates/pdf/(:num)',            'EstimateController::pdf/$1');
    $routes->get(   'estimates/pdf-download/(:num)',   'EstimateController::pdfDownload/$1');
    $routes->post(  'estimates/create',                'EstimateController::create');
    $routes->post(  'estimates/update/(:num)',         'EstimateController::update/$1');
    $routes->put(   'estimates/update/(:num)',         'EstimateController::update/$1');
    $routes->delete('estimates/delete/(:num)',         'EstimateController::delete/$1');
    $routes->post(  'estimates/change-status',         'EstimateController::changeStatus');
    $routes->post(  'estimates/send-email/(:num)',     'EstimateController::sendEmail/$1');
    $routes->post(  'estimates/convert/(:num)',        'EstimateController::convert/$1');
    $routes->get( 'estimates/(:num)/tasks',            'EstimateController::tasks/$1');
    $routes->get( 'estimates/(:num)/reminders',        'EstimateController::reminders/$1');
    $routes->post('estimates/(:num)/reminders',        'EstimateController::addReminder/$1');
    $routes->get( 'estimates/client-list',            'EstimateController::clientList');
    $routes->get( 'estimates/client-detail/(:num)',   'EstimateController::clientDetail/$1');
    $routes->post('estimates/client-respond/(:num)',  'EstimateController::clientRespond/$1');

    // ========================================
    // INVOICES (FACTURES)
    // ========================================
    $routes->get( 'invoices/client-list',               'InvoiceController::clientList');
    $routes->get( 'invoices/client-dashboard-stats',    'InvoiceController::clientDashboardStats');
    $routes->get( 'invoices/client-detail/(:num)',      'InvoiceController::clientDetail/$1');
    $routes->get( 'invoices/pdf/(:num)',                'InvoiceController::pdf/$1');
    $routes->get(   'invoices/list',                'InvoiceController::list');
    $routes->get(   'invoices/next-number',         'InvoiceController::nextNumber');
    $routes->get(   'invoices/detail/(:num)',        'InvoiceController::detail/$1');
    $routes->get(   'invoices/pdf-download/(:num)', 'InvoiceController::pdfDownload/$1');
    $routes->post(  'invoices/send-email/(:num)',   'InvoiceController::sendEmail/$1');
    $routes->post(  'invoices/create',              'InvoiceController::create');
    $routes->put(   'invoices/update/(:num)',        'InvoiceController::update/$1');
    $routes->delete('invoices/delete/(:num)',        'InvoiceController::delete/$1');
    $routes->post(  'invoices/change-status',       'InvoiceController::changeStatus');
    $routes->get('invoices/countries',  'InvoiceController::countries');
    $routes->get('invoices/currencies', 'InvoiceController::currencies');
    $routes->get('invoices/staff-list', 'InvoiceController::staffList');
    $routes->get( 'invoices/(:num)/tasks',         'InvoiceController::tasks/$1');
    $routes->get( 'invoices/(:num)/reminders',     'InvoiceController::reminders/$1');
    $routes->post('invoices/(:num)/reminders',     'InvoiceController::addReminder/$1');

    // ========================================
    // PAYMENTS (RÈGLEMENTS)
    // ========================================
    $routes->get(   'payments/list',          'PaymentController::list');
    $routes->get(   'payments/detail/(:num)', 'PaymentController::detail/$1');
    $routes->get(   'payments/modes',         'PaymentController::modes');
    $routes->post(  'payments/create',        'PaymentController::create');
    $routes->put(   'payments/update/(:num)', 'PaymentController::update/$1');
    $routes->delete('payments/delete/(:num)', 'PaymentController::delete/$1');

    // ========================================
    // ITEMS
    // ========================================
    $routes->get(   'items/search',            'ItemController::search');
    $routes->get(   'items/taxes',             'ItemController::taxes');
    $routes->get(   'items/units',             'ItemController::units');
    $routes->get(   'items',                   'ItemController::index');
    $routes->get(   'items/(:num)',            'ItemController::show/$1');
    $routes->post(  'items',                   'ItemController::create');
    $routes->put(   'items/(:num)',            'ItemController::update/$1');
    $routes->delete('items/(:num)',            'ItemController::delete/$1');
    $routes->post(  'items/(:num)/duplicate',  'ItemController::duplicate/$1');

    // ========================================
    // TASKS
    // ========================================
    $routes->get(   'tasks/related-documents',      'TaskController::relatedDocuments');
    $routes->get(   'tasks/statuses',               'TaskController::statuses');
    $routes->get(   'tasks/completed',              'TaskController::completed');
    $routes->get(   'tasks',                        'TaskController::index');
    $routes->post(  'tasks',                        'TaskController::create');
    $routes->post(  'tasks/(:num)/timer/start',     'TaskController::startTimer/$1');
    $routes->post(  'tasks/(:num)/timer/stop',      'TaskController::stopTimer/$1');
    $routes->get(   'tasks/(:num)/checklist',       'TaskController::getChecklist/$1');
    $routes->post(  'tasks/(:num)/checklist',       'TaskController::addChecklist/$1');
    $routes->put(   'tasks/checklist/(:num)',        'TaskController::updateChecklist/$1');
    $routes->delete('tasks/checklist/(:num)',        'TaskController::deleteChecklist/$1');
    $routes->get(   'tasks/files/(:num)/download',  'TaskController::downloadFile/$1');
    $routes->get(   'tasks/(:num)/files',           'TaskController::getFiles/$1');
    $routes->post(  'tasks/(:num)/files',           'TaskController::uploadFile/$1');
    $routes->get(   'tasks/(:num)/comments',        'TaskController::getComments/$1');
    $routes->post(  'tasks/(:num)/comments',        'TaskController::addComment/$1');
    $routes->get(   'tasks/(:num)/reminders',       'TaskController::getReminders/$1');
    $routes->post(  'tasks/(:num)/reminders',       'TaskController::addReminder/$1');
    $routes->get(   'tasks/(:num)',                 'TaskController::show/$1');
    $routes->put(   'tasks/(:num)',                 'TaskController::update/$1');
    $routes->delete('tasks/(:num)',                 'TaskController::delete/$1');

    // ========================================
    // SIGNATURE
    // ========================================
    $routes->post('signature/save',          'SignatureController::save');
    $routes->get( 'signature/(:any)/(:num)', 'SignatureController::get/$1/$2');

    // ========================================
    // STRIPE & PAYMEE
    // ========================================
    $routes->post('payments/stripe/create-intent', 'PaymentController::createStripeIntent');
    $routes->post('payments/stripe/confirm',        'PaymentController::confirmStripePayment');
    $routes->post('payments/paymee/create',         'PaymentController::createPaymeePayment');
    $routes->post('payments/paymee/confirm',        'PaymentController::confirmPaymeePayment');
    $routes->post('payments/paymee/webhook',        'PaymentController::paymeeWebhook');
    $routes->get('paymee/success', 'PaymentController::paymeeSuccess');
    $routes->get('paymee/fail',    'PaymentController::paymeeFail');

    $routes->get('invoices/generate-ref',  'InvoiceController::generateRef');
    $routes->get('estimates/generate-ref', 'EstimateController::generateRef');
    $routes->get('payments/generate-ref',  'PaymentController::generateRef');
    $routes->get('staff/list',             'StaffController::list');
    $routes->get('clients',        'ClientController::index');
    $routes->get('clients/(:num)', 'ClientController::show/$1');

    // ========================================
    // CREDIT NOTES (NOTES DE CRÉDIT)
    // ========================================
    $routes->get('credit-notes/list',                   'CreditNoteController::list');
    $routes->get('credit-notes/detail',                 'CreditNoteController::detail');
    $routes->get('credit-notes/next-number',            'CreditNoteController::nextNumber');
    $routes->get('credit-notes/creditable-invoices',    'CreditNoteController::creditableInvoices');
    $routes->get('credit-notes/payment-modes',          'CreditNoteController::paymentModes');
    $routes->get('credit-notes/pdf/(:num)',             'CreditNoteController::pdf/$1');
    $routes->post('credit-notes/create',                'CreditNoteController::create');
    $routes->put( 'credit-notes/update/(:num)',         'CreditNoteController::update/$1');
    $routes->post('credit-notes/apply-credits',         'CreditNoteController::applyCredits');
    $routes->post('credit-notes/create-refund',         'CreditNoteController::createRefund');
    $routes->post('credit-notes/delete-refund',         'CreditNoteController::deleteRefund');
    $routes->post('credit-notes/delete-applied-credit', 'CreditNoteController::deleteAppliedCredit');
    $routes->post('credit-notes/mark-void',             'CreditNoteController::markVoid');
    $routes->post('credit-notes/mark-open',             'CreditNoteController::markOpen');
    $routes->post('credit-notes/send-email/(:num)',     'CreditNoteController::sendEmail/$1');
    $routes->post('credit-notes/delete',                'CreditNoteController::delete');

    // ========================================
    // EXPENSES (DÉPENSES)
    // ========================================
    $routes->get( 'expenses/list',           'ExpenseController::list');
    $routes->get( 'expenses/categories',     'ExpenseController::categories');
    $routes->post('expenses/categories',     'ExpenseController::createCategory');
    $routes->post('expenses/create',         'ExpenseController::create');
    $routes->get( 'expenses/staff',          'ExpenseController::staffList');
    $routes->get( 'expenses/generate-ref',   'ExpenseController::generateRef');
    $routes->get( 'expenses/payment-modes',  'ExpenseController::paymentModes');
    $routes->get( 'expenses/currencies',     'ExpenseController::currencies');
    $routes->get( 'expenses/taxes',          'ExpenseController::taxes');
    $routes->get( 'expenses/clients',        'ExpenseController::clients');
    $routes->get   ('expenses/(:num)',                    'ExpenseController::detail/$1');
    $routes->put   ('expenses/(:num)',                    'ExpenseController::update/$1');
    $routes->delete('expenses/(:num)',                    'ExpenseController::delete/$1');
    $routes->post  ('expenses/(:num)/receipt',            'ExpenseController::uploadReceipt/$1');
    $routes->post  ('expenses/(:num)/convert-to-invoice', 'ExpenseController::convertToInvoice/$1');
    $routes->get   ('expenses/(:num)/tasks',              'ExpenseController::tasks/$1');
    $routes->post  ('expenses/(:num)/tasks',              'ExpenseController::createTask/$1');
    $routes->get   ('expenses/(:num)/reminders',          'ExpenseController::reminders/$1');
    $routes->post  ('expenses/(:num)/reminders',          'ExpenseController::createReminder/$1');
    $routes->get   ('reminders/check',  'ReminderController::check');
    $routes->put   ('reminders/(:num)', 'ExpenseController::updateReminder/$1');
    $routes->delete('reminders/(:num)', 'ExpenseController::deleteReminder/$1');

    // ========================================
    // NOTIFICATIONS (FCM Firebase)
    // ========================================
    $routes->post(  'notifications/register-token', 'NotificationController::registerToken');
    $routes->get(   'notifications/unread-count',   'NotificationController::unreadCount');
    $routes->put(   'notifications/read-all',       'NotificationController::markAllRead');
    $routes->get(   'notifications',                'NotificationController::index');
    $routes->put(   'notifications/(:num)/read',    'NotificationController::markRead/$1');
    $routes->delete('notifications/(:num)',         'NotificationController::delete/$1');
});