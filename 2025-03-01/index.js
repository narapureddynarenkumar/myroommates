const express = require("express");
const cors = require("cors");
const app = express();
const http = require("http").Server(app);
require('dotenv').config();

const authRoutes = require("./routes/authRoutes");
const roomRoutes = require('./routes/roomRoutes');


const port = 3001;


app.use(cors());
app.use(express.json());


app.use("/api/user", authRoutes);
app.use("/api/room", roomRoutes);

http.listen(port, () => console.log("L", "server running..."));