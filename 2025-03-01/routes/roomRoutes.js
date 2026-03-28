const express = require('express');
const router = express.Router();
const room = require('../controllers/roomController');

router.get('/rooms/:user_id', room.getRooms);
router.post('/rooms', room.createRoom);
router.put('/rooms', room.updateRoom);

module.exports = router;