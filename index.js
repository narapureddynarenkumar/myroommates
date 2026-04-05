const express = require("express");
const cors = require("cors");
const app = express();
const http = require("http").Server(app);
require('dotenv').config();

const authRoutes = require("./routes/authRoutes");
const roomRoutes = require('./routes/roomRoutes');
const memberRoutes = require("./routes/memberRoutes")
const expenseRoutes = require("./routes/expenseRoutes")
const roomCategoryRoutes = require("./routes/roomCategoryRoutes")
const calculationRoutes = require("./routes/calculationRoutes")


const port = 3001;


app.use(cors());
app.use(express.json());


app.use("/api/user", authRoutes);
app.use("/api/room", roomRoutes);
app.use("/api/members", memberRoutes);
app.use("/api/expenses", expenseRoutes);
app.use("/api/room-categories", roomCategoryRoutes);
app.use("/api/split", calculationRoutes);


http.listen(port, () => console.log("L", "server running..."));