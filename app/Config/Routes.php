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
    $routes->get('profile',                  'ProfileController::getProfile');
    $routes->post('profile/update',          'ProfileController::updateContact');
    $routes->post('profile/update-company',  'ProfileController::updateCompany');
    $routes->get('profile/countries',        'ProfileController::getCountries');
    $routes->post('profile/upload-image',    'ProfileController::uploadImage');
    $routes->post('profile/change-password', 'ProfileController::changePassword');

    // ========================================
    // STAFF
    // ========================================
    $routes->post('staff/register',        'StaffController::register');
    $routes->post('staff/verify-otp',      'StaffController::verifyOtp');
    $routes->post('staff/resend-otp',      'StaffController::resendOtp');
    $routes->post('staff/update-profile',  'StaffController::updateProfile');

    // ========================================
    // PROPOSALS
    // ========================================
    $routes->get('proposals/list',               'ProposalController::list');
    $routes->get('proposals/detail/(:num)',       'ProposalController::detail/$1');
    $routes->post('proposals/create',             'ProposalController::create');
    $routes->post('proposals/update/(:num)',      'ProposalController::update/$1');
    $routes->post('proposals/change-status',      'ProposalController::changeStatus');
    $routes->post('proposals/send-email/(:num)',  'ProposalController::sendEmail/$1');
    $routes->get('proposals/pdf/(:num)',          'ProposalController::pdf/$1');
    $routes->post('proposals/convert/(:num)',     'ProposalController::convert/$1');
    $routes->delete('proposals/delete/(:num)',    'ProposalController::delete/$1');

    // ── Données formulaire ──────────────────────────────────
    $routes->get('proposals/clients',             'ProposalController::clients');
    $routes->get('proposals/contacts',            'ProposalController::contacts');
    $routes->get('proposals/taxes',               'ProposalController::taxes');
    $routes->get('proposals/currencies',          'ProposalController::currencies');
    $routes->get('proposals/staff-list',          'ProposalController::staffList');
    $routes->get('proposals/countries',           'ProposalController::countries');
    $routes->get('proposals/next-number',         'ProposalController::nextNumber');

    // ── Client (lecture seule) ──────────────────────────────
    $routes->get('proposals/client-list',             'ProposalController::clientList');
    $routes->get('proposals/client-detail/(:num)',    'ProposalController::clientDetail/$1');
    $routes->get('proposals/client-pdf/(:num)',       'ProposalController::clientPdf/$1');
    $routes->post('proposals/client-respond/(:num)',  'ProposalController::clientRespond/$1');

    // ========================================
    // TICKETS
    // ========================================
    $routes->get('tickets/departments',                      'TicketController::getDepartments');
    $routes->get('tickets/priorities',                       'TicketController::getPriorities');
    $routes->get('tickets/statuses',                         'TicketController::getStatuses');
    $routes->post('tickets/create',                          'TicketController::create');
    $routes->get('tickets/list',                             'TicketController::clientList');
    $routes->get('tickets/all',                              'TicketController::staffList');
    $routes->get('tickets/detail/(:num)',                    'TicketController::detail/$1');
    $routes->post('tickets/reply',                           'TicketController::reply');
    $routes->post('tickets/change-status',                   'TicketController::changeStatus');
    $routes->post('tickets/upload',                          'TicketController::uploadAttachment');
    $routes->get('tickets/attachment/(:num)/(:segment)',     'TicketController::serveAttachment/$1/$2');

});