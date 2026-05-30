<?php
/**
 * SVSML-ERP — Crew Portal: Approvals (REMOVED)
 *
 * Removed in Module 18 — crew never see approval / dues / financial
 * data. Any old bookmark or stale browser tab now bounces back to
 * the portal Overview with a friendly notice.
 */
require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';

requireCrew();
flash('info', 'The Approvals section has been removed from the crew portal.');
header('Location: ' . url('crew-portal.php'));
exit;
