<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

$routes->get('/', 'Home::index');

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

    // ── Données formulaire
    $routes->get('proposals/clients',      'ProposalController::clients');
    $routes->get('proposals/contacts',     'ProposalController::contacts');
    $routes->get('proposals/taxes',        'ProposalController::taxes');
    $routes->get('proposals/currencies',   'ProposalController::currencies');
    $routes->get('proposals/staff-list',   'ProposalController::staffList');
    $routes->get('proposals/countries',    'ProposalController::countries');
    $routes->get('proposals/next-number',  'ProposalController::nextNumber');

    // ── Client (lecture seule)
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
    // Departements
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

    // ── Client
    $routes->get( 'estimates/client-list',            'EstimateController::clientList');
    $routes->get( 'estimates/client-detail/(:num)',   'EstimateController::clientDetail/$1');
    $routes->post('estimates/client-respond/(:num)',  'EstimateController::clientRespond/$1');

     // ========================================
    // INVOICES (FACTURES)
    // ========================================
 
    // ── Client
    $routes->get( 'invoices/client-list',          'InvoiceController::clientList');
    $routes->get( 'invoices/client-detail/(:num)', 'InvoiceController::clientDetail/$1');
    $routes->get( 'invoices/pdf/(:num)',            'InvoiceController::pdf/$1');
 
    // ── Staff
    $routes->get(   'invoices/list',                'InvoiceController::list');
    $routes->get(   'invoices/next-number',         'InvoiceController::nextNumber');
    $routes->get(   'invoices/detail/(:num)',        'InvoiceController::detail/$1');
    $routes->get(   'invoices/pdf-download/(:num)', 'InvoiceController::pdfDownload/$1');
    $routes->post(  'invoices/send-email/(:num)',   'InvoiceController::sendEmail/$1');   // ← AJOUTÉ
    $routes->post(  'invoices/create',              'InvoiceController::create');
    $routes->put(   'invoices/update/(:num)',        'InvoiceController::update/$1');
    $routes->delete('invoices/delete/(:num)',        'InvoiceController::delete/$1');
    $routes->post(  'invoices/change-status',       'InvoiceController::changeStatus');
    // ========================================
    // PAYMENTS (RÈGLEMENTS)
    // ========================================
    $routes->get(   'payments/list',          'PaymentController::list');
    $routes->get(   'payments/detail/(:num)', 'PaymentController::detail/$1');
    $routes->get(   'payments/modes',         'PaymentController::modes');
    $routes->post(  'payments/create',        'PaymentController::create');
    $routes->put(   'payments/update/(:num)', 'PaymentController::update/$1');
    $routes->delete('payments/delete/(:num)', 'PaymentController::delete/$1');

    // ── Stripe (paiement en ligne)
    $routes->post('payments/stripe/create-intent', 'PaymentController::createStripeIntent');
    $routes->post('payments/stripe/confirm',        'PaymentController::confirmStripePayment');
});