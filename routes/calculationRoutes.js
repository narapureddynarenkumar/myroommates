const express = require('express');
const router = express.Router();
const calculation = require('../controllers/calculationController');

router.get('/monthly-calculation', calculation.getMonthlyCalculation);
router.post('/save-snapshot', calculation.saveSnapshot);
router.post('/unfreeze-snapshot', calculation.unfreezeSnapshot)
router.get('/frozen-snapshots/:room_id',calculation.getFrozenSnapshots)
router.post('/settlement/mark-paid',calculation.markSettlementPaid)
router.get('/snapshot-settlements/:snapshotId', calculation.getSnapshotSettlements);

module.exports = router;