const express = require('express');
const router = express.Router();
const room = require('../controllers/roomController');

router.get('/my-rooms/:user_id', room.getRooms);
router.post('/create-room', room.createRoom);
router.put('/rooms', room.updateRoom);
router.get('/room-settings/:room_id', room.getRoomMembers);
router.post('/update-room',room.updateRoom)

module.exports = router;