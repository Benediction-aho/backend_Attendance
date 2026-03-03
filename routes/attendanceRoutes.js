const express = require('express');
const { protect, adminOnly } = require('../middleware/auth');
const {
  checkIn, checkOut, getTodayStatus,
  getMyAttendance, getAllAttendance, getAttempts,
} = require('../controllers/attendanceController');

const router = express.Router();

/**
 * @openapi
 * /attendance/checkin:
 *   post:
 *     tags: [Attendance]
 *     summary: Check in (requires geolocation)
 *     requestBody:
 *       required: true
 *       content:
 *         application/json:
 *           schema:
 *             type: object
 *             required: [latitude, longitude]
 *             properties:
 *               latitude: { type: number }
 *               longitude: { type: number }
 *     responses:
 *       201:
 *         description: Check-in successful
 *       403:
 *         description: Out of perimeter
 */
router.post('/checkin', protect, checkIn);

/**
 * @openapi
 * /attendance/checkout:
 *   post:
 *     tags: [Attendance]
 *     summary: Check out
 *     responses:
 *       200:
 *         description: Check-out successful
 */
router.post('/checkout', protect, checkOut);

/**
 * @openapi
 * /attendance/today:
 *   get:
 *     tags: [Attendance]
 *     summary: Get today's attendance status
 *     responses:
 *       200:
 *         description: Today's attendance data
 */
router.get('/today', protect, getTodayStatus);

/**
 * @openapi
 * /attendance/my:
 *   get:
 *     tags: [Attendance]
 *     summary: Get my attendance history
 *     parameters:
 *       - in: query
 *         name: startDate
 *         schema: { type: string, format: date }
 *       - in: query
 *         name: endDate
 *         schema: { type: string, format: date }
 */
router.get('/my', protect, getMyAttendance);

/**
 * @openapi
 * /attendance/all:
 *   get:
 *     tags: [Attendance]
 *     summary: Get all attendances (admin only)
 */
router.get('/all', protect, adminOnly, getAllAttendance);

/**
 * @openapi
 * /attendance/attempts:
 *   get:
 *     tags: [Attendance]
 *     summary: Get failed check-in attempts (admin only)
 */
router.get('/attempts', protect, adminOnly, getAttempts);

module.exports = router;
