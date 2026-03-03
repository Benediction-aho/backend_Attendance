const User = require('../models/User');
const Attendance = require('../models/Attendance');
const Task = require('../models/Task');
const CheckInAttempt = require('../models/CheckInAttempt');
const { generateToken } = require('../middleware/auth');
const bcrypt = require('bcryptjs');

// @desc    Create employee
// @route   POST /api/admin/users
const createUser = async (req, res, next) => {
  try {
    const { firstName, lastName, email, password, employeeType, position, role } = req.body;

    const existingUser = await User.findOne({ email });
    if (existingUser) {
      return res.status(400).json({ success: false, message: 'Email already exists' });
    }

    const user = await User.create({
      firstName, lastName, email, password,
      employeeType: employeeType || 'employe',
      position,
      role: role || 'employee',
    });

    res.status(201).json({
      success: true,
      user: {
        _id: user._id, firstName: user.firstName, lastName: user.lastName,
        email: user.email, role: user.role, employeeType: user.employeeType, position: user.position,
      },
    });
  } catch (error) {
    next(error);
  }
};

// @desc    Get all employees
// @route   GET /api/admin/users
const getUsers = async (req, res, next) => {
  try {
    const users = await User.find({ isDeleted: false }).select('-password').sort({ createdAt: -1 });
    res.json({ success: true, users });
  } catch (error) {
    next(error);
  }
};

// @desc    Block/unblock employee
// @route   PATCH /api/admin/users/:id/block
const toggleBlock = async (req, res, next) => {
  try {
    const user = await User.findById(req.params.id);
    if (!user || user.isDeleted) {
      return res.status(404).json({ success: false, message: 'User not found' });
    }

    user.isBlocked = !user.isBlocked;
    await user.save();

    res.json({ success: true, message: `User ${user.isBlocked ? 'blocked' : 'unblocked'}`, isBlocked: user.isBlocked });
  } catch (error) {
    next(error);
  }
};

// @desc    Delete employee
// @route   DELETE /api/admin/users/:id
const deleteUser = async (req, res, next) => {
  try {
    const user = await User.findById(req.params.id);
    if (!user) {
      return res.status(404).json({ success: false, message: 'User not found' });
    }

    user.isDeleted = true;
    user.isBlocked = true;
    await user.save();

    res.json({ success: true, message: 'User deleted successfully' });
  } catch (error) {
    next(error);
  }
};

// @desc    Get analytics dashboard data
// @route   GET /api/admin/analytics
const getAnalytics = async (req, res, next) => {
  try {
    const { startDate, endDate } = req.query;
    const dateFilter = {};
    if (startDate) dateFilter.$gte = startDate;
    if (endDate) dateFilter.$lte = endDate;

    const [users, attendances, tasks, attempts] = await Promise.all([
      User.find({ isDeleted: false, role: 'employee' }).select('-password'),
      Attendance.find(Object.keys(dateFilter).length ? { date: dateFilter } : {}).populate('userId', 'firstName lastName email'),
      Task.find(Object.keys(dateFilter).length ? { date: dateFilter } : {}).populate('userId', 'firstName lastName email'),
      CheckInAttempt.find(Object.keys(dateFilter).length ? { date: dateFilter } : {}).populate('userId', 'firstName lastName email'),
    ]);

    const totalEmployees = users.length;
    const totalAttendances = attendances.length;
    const lateCount = attendances.filter(a => a.isLate).length;
    const earlyLeaveCount = attendances.filter(a => a.earlyLeave).length;
    const totalHours = attendances.reduce((sum, a) => sum + (a.hoursWorked || 0), 0);
    const completedTasks = tasks.filter(t => t.status === 'completed').length;

    res.json({
      success: true,
      stats: {
        totalEmployees,
        totalAttendances,
        lateCount,
        earlyLeaveCount,
        totalHours: parseFloat(totalHours.toFixed(2)),
        completedTasks,
        pendingTasks: tasks.length - completedTasks,
        totalAttempts: attempts.length,
      },
      attendances,
      tasks,
      attempts,
      users,
    });
  } catch (error) {
    next(error);
  }
};

// @desc    Get employee stats
// @route   GET /api/admin/users/:id/stats
const getUserStats = async (req, res, next) => {
  try {
    const { id } = req.params;
    const [attendances, tasks, attempts] = await Promise.all([
      Attendance.find({ userId: id }).sort({ date: -1 }),
      Task.find({ userId: id }).sort({ date: -1 }),
      CheckInAttempt.find({ userId: id }).sort({ date: -1 }),
    ]);

    res.json({
      success: true,
      stats: {
        totalPresence: attendances.length,
        lateCount: attendances.filter(a => a.isLate).length,
        earlyLeaveCount: attendances.filter(a => a.earlyLeave).length,
        totalHours: parseFloat(attendances.reduce((s, a) => s + (a.hoursWorked || 0), 0).toFixed(2)),
        totalTasks: tasks.length,
        completedTasks: tasks.filter(t => t.status === 'completed').length,
        totalAttempts: attempts.length,
      },
      attendances,
      tasks,
      attempts,
    });
  } catch (error) {
    next(error);
  }
};

module.exports = { createUser, getUsers, toggleBlock, deleteUser, getAnalytics, getUserStats };
