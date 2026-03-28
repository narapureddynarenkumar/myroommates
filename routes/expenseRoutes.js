const express = require("express");
const router = express.Router();
const expenseController = require("../controllers/expenseController")

const controller = new expenseController(db);