const Task = require('../models/Task');
const Attendance = require('../models/Attendance');

const getTodayDate = () => new Date().toISOString().split('T')[0];

const checkCheckedIn = async (userId) => {
  const date = getTodayDate();
  const attendance = await Attendance.findOne({ userId, date });
  return !!attendance;
};

// @desc    Create task
// @route   POST /api/tasks
const createTask = async (req, res, next) => {
  try {
    const hasCheckedIn = await checkCheckedIn(req.user._id);
    if (!hasCheckedIn) {
      return res.status(403).json({ success: false, message: 'You must check in before managing tasks' });
    }

    const { description, difficulties, status } = req.body;
    const task = await Task.create({
      userId: req.user._id,
      description,
      difficulties,
      status,
      date: getTodayDate(),
    });

    res.status(201).json({ success: true, task });
  } catch (error) {
    next(error);
  }
};

// @desc    Get my tasks
// @route   GET /api/tasks
const getMyTasks = async (req, res, next) => {
  try {
    const { date } = req.query;
    const filter = { userId: req.user._id };
    if (date) filter.date = date;

    const tasks = await Task.find(filter).sort({ createdAt: -1 });
    res.json({ success: true, tasks });
  } catch (error) {
    next(error);
  }
};

// @desc    Update task
// @route   PUT /api/tasks/:id
const updateTask = async (req, res, next) => {
  try {
    const hasCheckedIn = await checkCheckedIn(req.user._id);
    if (!hasCheckedIn) {
      return res.status(403).json({ success: false, message: 'You must check in before managing tasks' });
    }

    const task = await Task.findOne({ _id: req.params.id, userId: req.user._id });
    if (!task) {
      return res.status(404).json({ success: false, message: 'Task not found' });
    }

    const { description, difficulties, status } = req.body;
    if (description) task.description = description;
    if (difficulties !== undefined) task.difficulties = difficulties;
    if (status) task.status = status;
    await task.save();

    res.json({ success: true, task });
  } catch (error) {
    next(error);
  }
};

// @desc    Delete task
// @route   DELETE /api/tasks/:id
const deleteTask = async (req, res, next) => {
  try {
    const hasCheckedIn = await checkCheckedIn(req.user._id);
    if (!hasCheckedIn) {
      return res.status(403).json({ success: false, message: 'You must check in before managing tasks' });
    }

    const task = await Task.findOneAndDelete({ _id: req.params.id, userId: req.user._id });
    if (!task) {
      return res.status(404).json({ success: false, message: 'Task not found' });
    }

    res.json({ success: true, message: 'Task deleted' });
  } catch (error) {
    next(error);
  }
};

// @desc    Get all tasks (admin)
// @route   GET /api/tasks/all
const getAllTasks = async (req, res, next) => {
  try {
    const { userId, date } = req.query;
    const filter = {};
    if (userId) filter.userId = userId;
    if (date) filter.date = date;

    const tasks = await Task.find(filter)
      .populate('userId', 'firstName lastName email')
      .sort({ createdAt: -1 });

    res.json({ success: true, tasks });
  } catch (error) {
    next(error);
  }
};

module.exports = { createTask, getMyTasks, updateTask, deleteTask, getAllTasks };
