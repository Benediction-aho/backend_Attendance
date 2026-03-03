const express = require('express');
const { protect, adminOnly } = require('../middleware/auth');
const { createUser, getUsers, toggleBlock, deleteUser, getAnalytics, getUserStats } = require('../controllers/adminController');

const router = express.Router();

router.use(protect, adminOnly);

/**
 * @openapi
 * /admin/users:
 *   post:
 *     tags: [Admin]
 *     summary: Create employee or admin
 *   get:
 *     tags: [Admin]
 *     summary: Get all employees
 */
router.route('/users')
  .post(createUser)
  .get(getUsers);

/**
 * @openapi
 * /admin/users/{id}/block:
 *   patch:
 *     tags: [Admin]
 *     summary: Block/unblock employee
 */
router.patch('/users/:id/block', toggleBlock);

/**
 * @openapi
 * /admin/users/{id}:
 *   delete:
 *     tags: [Admin]
 *     summary: Delete employee
 */
router.delete('/users/:id', deleteUser);

/**
 * @openapi
 * /admin/analytics:
 *   get:
 *     tags: [Admin]
 *     summary: Get analytics dashboard data
 */
router.get('/analytics', getAnalytics);

/**
 * @openapi
 * /admin/users/{id}/stats:
 *   get:
 *     tags: [Admin]
 *     summary: Get individual employee stats
 */
router.get('/users/:id/stats', getUserStats);

module.exports = router;
