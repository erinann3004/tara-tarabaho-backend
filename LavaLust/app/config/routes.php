<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Welcome::index');

$router->get('/api/me', 'TaraApi::me');
$router->post('/api/login', 'TaraApi::login');
$router->post('/api/register', 'TaraApi::register');
$router->post('/api/logout', 'TaraApi::logout');
$router->get('/api/jobs', 'TaraApi::jobs');
$router->get('/api/trainings', 'TaraApi::trainings');
$router->get('/api/recommendations', 'TaraApi::recommendations');
$router->get('/api/dashboard', 'TaraApi::dashboard');
$router->get('/api/applications', 'TaraApi::applications');
$router->post('/api/applications', 'TaraApi::apply');
$router->get('/api/applications/resume', 'TaraApi::downloadResume');
$router->post('/api/applications/status', 'TaraApi::updateApplication');
$router->post('/api/applications/withdraw', 'TaraApi::withdrawApplication');
$router->get('/api/notifications', 'TaraApi::notifications');
$router->post('/api/notifications/read', 'TaraApi::markNotificationRead');
$router->post('/api/notifications/read-all', 'TaraApi::markAllNotificationsRead');
$router->post('/api/notification-preferences', 'TaraApi::updateNotificationPreferences');
$router->post('/api/profile', 'TaraApi::updateProfile');
$router->post('/api/profile/photo', 'TaraApi::updateProfilePhoto');
$router->post('/api/listings', 'TaraApi::createListing');
$router->get('/api/listings/mine', 'TaraApi::myListings');
$router->post('/api/listings/update-job', 'TaraApi::updateJobListing');
$router->post('/api/listings/close-job', 'TaraApi::closeJobListing');
$router->post('/api/listings/close-training', 'TaraApi::closeTrainingListing');
$router->post('/api/listings/moderate', 'TaraApi::moderateListing');
$router->get('/api/push-key', 'TaraApi::pushKey');
$router->post('/api/push-subscriptions', 'TaraApi::savePushSubscription');
$router->post('/api/push-subscriptions/remove', 'TaraApi::removePushSubscription');
