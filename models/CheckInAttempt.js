const mongoose = require('mongoose');

const checkInAttemptSchema = new mongoose.Schema(
  {
    userId: {
      type: mongoose.Schema.Types.ObjectId,
      ref: 'User',
      required: true,
    },
    date: { type: String, required: true },
    attemptTime: { type: String, required: true },
    latitude: { type: Number, required: true },
    longitude: { type: Number, required: true },
    distance: { type: Number, required: true }, // in meters
    reason: { type: String, default: 'Out of perimeter' },
  },
  { timestamps: true }
);

checkInAttemptSchema.index({ userId: 1 });
checkInAttemptSchema.index({ date: 1 });

module.exports = mongoose.model('CheckInAttempt', checkInAttemptSchema);
