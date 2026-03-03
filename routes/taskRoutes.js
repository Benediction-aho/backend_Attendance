const express = require('express');
const { protect, adminOnly } = require('../middleware/auth');
const { createTask, getMyTasks, updateTask, deleteTask, getAllTasks } = require('../controllers/taskController');

const router = express.Router();

/**
 * @openapi
 * /tasks:
 *   post:
 *     tags: [Tasks]
 *     summary: Create a task (requires check-in)
 *     requestBody:
 *       required: true
 *       content:
 *         application/json:
 *           schema:
 *             type: object
 *             required: [description]
 *             properties:
 *               description: { type: string }
 *               difficulties: { type: string }
 *               status: { type: string, enum: [completed, pending] }
 *   get:
 *     tags: [Tasks]
 *     summary: Get my tasks
 */
router.route('/')
  .post(protect, createTask)
  .get(protect, getMyTasks);

/**
 * @openapi
 * /tasks/all:
 *   get:
 *     tags: [Tasks]
 *     summary: Get all tasks (admin only)
 */
router.get('/all', protect, adminOnly, getAllTasks);

/**
 * @openapi
 * /tasks/{id}:
 *   put:
 *     tags: [Tasks]
 *     summary: Update a task
 *   delete:
 *     tags: [Tasks]
 *     summary: Delete a task
 */
router.route('/:id')
  .put(protect, updateTask)
  .delete(protect, deleteTask);

module.exports = router;
