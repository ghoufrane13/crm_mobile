<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// Route par défaut
$routes->get('/', 'Home::index');

// ✅ CORRECTION : Supprimer le namespace dans le group
$routes->group('api', function ($routes) {
    
    // ========================================
    // INSCRIPTION
    // ========================================
    $routes->post('register/company', 'RegisterController::registerCompany');
    $routes->post('register/contact', 'RegisterController::registerContact');
    
    // ========================================
    // ACTIVATION
    // ========================================
    $routes->post('activate-account', 'RegisterController::activateAccount');
    
    // ========================================
    // CONNEXION
    // ========================================
    $routes->post('login', 'LoginController::login');
    $routes->get('user', 'LoginController::getUser');
    $routes->post('refresh-token', 'LoginController::refreshToken');
    
    // ========================================
    // DÉCONNEXION
    // ========================================
    $routes->post('logout', 'LogoutController::logout');
    
    // ========================================
    // RÉINITIALISATION DE MOT DE PASSE
    // ========================================
    $routes->post('password/request-reset', 'PasswordResetController::requestReset');
    $routes->post('password/verify-code', 'PasswordResetController::verifyResetCode');
    $routes->post('password/reset', 'PasswordResetController::resetPassword');
});