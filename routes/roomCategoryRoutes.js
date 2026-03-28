const express = require("express");
const router = express.Router();
const db = require("../config/db"); // your mysql pool
const RoomCategoryController = require("../controllers/roomCategory.controller");

const controller = new RoomCategoryController(db);

router.get("/:room_id", (req, res) => controller.getByRoom(req, res));
router.post("/", (req, res) => controller.create(req, res));
router.put("/:id", (req, res) => controller.update(req, res));
router.delete("/:id", (req, res) => controller.delete(req, res));

module.exports = router;