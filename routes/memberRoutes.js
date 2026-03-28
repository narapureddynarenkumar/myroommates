const express = require('express');
const router = express.Router();
const member = require('../controllers/memberController');

router.get('/members/:roomId', member.getRoomMembers);
router.get('/members/:year/:month', member.getMembersByMonth);
router.post('/members', member.addMember);
router.post('/members/rule', member.saveMonthlyRule);
router.put('/members/rule', member.updateRule);
router.post('/members/leave', member.leaveRoom);

module.exports = router;