const express = require('express');
const router = express.Router();
const member = require('../controllers/memberController');

router.get('/:roomId', member.getRoomMembers);
router.post('/save-member', member.addMember);
router.post('/save-rule', member.saveMonthlyRule);
router.put('/update-rule', member.updateRule);
router.post('/leave-room', member.leaveRoom);

module.exports = router;