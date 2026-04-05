const express = require("express");
const router = express.Router();
const db = require("../config/db"); // your mysql pool
const roomCategory = require("../controllers/roomCategoryController");



router.get("/:room_id",roomCategory.getByRoom);
router.post("/create-category",  roomCategory.create);
router.post("/update-category", roomCategory.update);
router.post("/delete-category",roomCategory.delete);

module.exports = router;