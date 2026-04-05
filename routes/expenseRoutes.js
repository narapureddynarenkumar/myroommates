const express = require("express");
const router = express.Router();
const expenseController = require("../controllers/expenseController");

router.post("/create-expense", expenseController.createExpense);
router.get("/:room_id/:month/:year", expenseController.getAllExpenses);
router.post("/update-expense/", expenseController.updateExpense);
router.post("/delete-expense/", expenseController.deleteExpense);

module.exports = router;