const Attendance = require('../models/Attendance');
const Task = require('../models/Task');
const CheckInAttempt = require('../models/CheckInAttempt');

// @desc    Get my stats
// @route   GET /api/stats/me
const getMyStats = async (req, res, next) => {
  try {
    const userId = req.user._id;
    const { startDate, endDate } = req.query;

    const dateFilter = {};
    if (startDate) dateFilter.$gte = startDate;
    if (endDate) dateFilter.$lte = endDate;
    const hasDateFilter = Object.keys(dateFilter).length > 0;

    const [attendances, tasks, attempts] = await Promise.all([
      Attendance.find({ userId, ...(hasDateFilter ? { date: dateFilter } : {}) }).sort({ date: -1 }),
      Task.find({ userId, ...(hasDateFilter ? { date: dateFilter } : {}) }).sort({ date: -1 }),
      CheckInAttempt.find({ userId, ...(hasDateFilter ? { date: dateFilter } : {}) }).sort({ createdAt: -1 }),
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
        pendingTasks: tasks.filter(t => t.status === 'pending').length,
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

module.exports = { getMyStats };
