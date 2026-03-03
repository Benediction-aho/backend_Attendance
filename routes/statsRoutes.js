const express = require('express');
const { protect } = require('../middleware/auth');
const { getMyStats } = require('../controllers/statsController');

const router = express.Router();

/**
 * @openapi
 * /stats/me:
 *   get:
 *     tags: [Stats]
 *     summary: Get my personal stats
 */
router.get('/me', protect, getMyStats);

module.exports = router;
