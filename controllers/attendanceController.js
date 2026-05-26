const Attendance = require('../models/Attendance');
const CheckInAttempt = require('../models/CheckInAttempt');
const { haversineDistance } = require('../utils/haversine');

const COMPANY_LAT = parseFloat(process.env.COMPANY_LAT || '5.595223');
const COMPANY_LNG = parseFloat(process.env.COMPANY_LNG || '-0.216703');
const COMPANY_RADIUS = parseFloat(process.env.COMPANY_RADIUS || '60');

const getTodayDate = () => new Date().toISOString().split('T')[0];
const getCurrentTime = () => new Date().toTimeString().split(' ')[0];

// @desc    Check In
// @route   POST /api/attendance/checkin
const checkIn = async (req, res, next) => {
  try {
    const { latitude, longitude } = req.body;

    if (latitude == null || longitude == null) {
      return res.status(400).json({
        success: false,
        message: 'Latitude and longitude are required',
      });
    }

    const userId = req.user._id;
    const date = getTodayDate();
    const time = getCurrentTime();

    const distance = haversineDistance(latitude, longitude, COMPANY_LAT, COMPANY_LNG);

    if (distance > COMPANY_RADIUS) {
      await CheckInAttempt.create({
        userId, date, attemptTime: time,
        latitude, longitude,
        distance: Math.round(distance),
        reason: 'Out of perimeter',
      });
      return res.status(403).json({
        success: false,
        message: `You are ${Math.round(distance)}m from the office. Must be within ${COMPANY_RADIUS}m.`,
        distance: Math.round(distance),
      });
    }

    const [h, m] = time.split(':').map(Number);
    const isLate = h > 8 || (h === 8 && m > 5);

    // Atomic upsert — eliminates race condition completely
    // $setOnInsert only runs on INSERT, not on find (idempotent)
    const result = await Attendance.findOneAndUpdate(
      { userId, date },
      {
        $setOnInsert: {
          userId,
          date,
          checkInTime: time,
          checkInLat: latitude,
          checkInLng: longitude,
          isLate,
          hoursWorked: 0,
          earlyLeave: false,
        },
      },
      {
        upsert: true,
        new: true,
        setDefaultsOnInsert: true,
      }
    );

    // result is ALWAYS a valid document — never null
    res.status(201).json({ success: true, attendance: result });
  } catch (error) {
    next(error);
  }
};

// @desc    Check Out
// @route   POST /api/attendance/checkout
const checkOut = async (req, res, next) => {
  try {
    const userId = req.user._id;
    const date = getTodayDate();
    const time = getCurrentTime();

    const attendance = await Attendance.findOne({ userId, date });
    if (!attendance) {
      return res.status(400).json({ success: false, message: 'No check-in found for today' });
    }

    if (attendance.checkOutTime) {
      return res.status(400).json({ success: false, message: 'Already checked out today' });
    }

    // Calculate hours worked
    const [inH, inM, inS] = attendance.checkInTime.split(':').map(Number);
    const [outH, outM, outS] = time.split(':').map(Number);
    const inSeconds = inH * 3600 + inM * 60 + inS;
    const outSeconds = outH * 3600 + outM * 60 + outS;
    const hoursWorked = parseFloat(((outSeconds - inSeconds) / 3600).toFixed(2));

    // Early leave check (before 17:00)
    const [h, m] = time.split(':').map(Number);
    const earlyLeave = h < 17;

    attendance.checkOutTime = time;
    attendance.hoursWorked = hoursWorked;
    attendance.earlyLeave = earlyLeave;
    await attendance.save();

    res.json({ success: true, attendance });
  } catch (error) {
    next(error);
  }
};

// @desc    Get today's attendance status
// @route   GET /api/attendance/today
const getTodayStatus = async (req, res, next) => {
  try {
    const date = getTodayDate();
    const attendance = await Attendance.findOne({ userId: req.user._id, date });
    res.json({ success: true, attendance });
  } catch (error) {
    next(error);
  }
};

// @desc    Get my attendance history
// @route   GET /api/attendance/my
const getMyAttendance = async (req, res, next) => {
  try {
    const { startDate, endDate } = req.query;
    const filter = { userId: req.user._id };
    if (startDate) filter.date = { $gte: startDate };
    if (endDate) filter.date = { ...filter.date, $lte: endDate };

    const records = await Attendance.find(filter).sort({ date: -1 });
    res.json({ success: true, records });
  } catch (error) {
    next(error);
  }
};

// @desc    Get all attendance (admin)
// @route   GET /api/attendance/all
const getAllAttendance = async (req, res, next) => {
  try {
    const { startDate, endDate, userId } = req.query;
    const filter = {};
    if (userId) filter.userId = userId;
    if (startDate) filter.date = { $gte: startDate };
    if (endDate) filter.date = { ...filter.date, $lte: endDate };

    const records = await Attendance.find(filter)
      .populate('userId', 'firstName lastName email position')
      .sort({ date: -1 });

    res.json({ success: true, records });
  } catch (error) {
    next(error);
  }
};

// @desc    Get check-in attempts (admin)
// @route   GET /api/attendance/attempts
const getAttempts = async (req, res, next) => {
  try {
    const { startDate, endDate, userId } = req.query;
    const filter = {};
    if (userId) filter.userId = userId;
    if (startDate) filter.date = { $gte: startDate };
    if (endDate) filter.date = { ...filter.date, $lte: endDate };

    const attempts = await CheckInAttempt.find(filter)
      .populate('userId', 'firstName lastName email')
      .sort({ createdAt: -1 });

    res.json({ success: true, attempts });
  } catch (error) {
    next(error);
  }
};

module.exports = { checkIn, checkOut, getTodayStatus, getMyAttendance, getAllAttendance, getAttempts };
