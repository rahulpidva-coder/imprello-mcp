import express from "express";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import { z } from "zod";

const API_BASE = "https://offspring.codeteam.in/mobileApp/IMP/dbprocess/mcp/api";

const mcp = new McpServer({
  name: "imprello-mcp",
  version: "1.0.0"
});

mcp.tool(
  "searchDealer",
  { term: z.string() },
  async ({ term }) => {
    const res = await fetch(`${API_BASE}/searchDealer.php?term=${encodeURIComponent(term)}`);
    const data = await res.json();
    return {
      content: [{ type: "text", text: JSON.stringify(data) }]
    };
  }
);

mcp.tool(
  "searchProduct",
  { term: z.string() },
  async ({ term }) => {
    const res = await fetch(`${API_BASE}/searchProduct.php?term=${encodeURIComponent(term)}`);
    const data = await res.json();
    return {
      content: [{ type: "text", text: JSON.stringify(data) }]
    };
  }
);

mcp.tool(
  "bookOrder",
  {
    merId: z.number(),
    items: z.array(
      z.object({
        pdctCode: z.string(),
        qty: z.number()
      })
    ),
    notes: z.string().optional().default("")
  },
  async ({ merId, items, notes }) => {
    const res = await fetch(`${API_BASE}/bookOrder.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ merId, items, notes })
    });
    const data = await res.json();
    return {
      content: [{ type: "text", text: JSON.stringify(data) }]
    };
  }
);

mcp.tool(
  "sendChallan",
  { orderId: z.number() },
  async ({ orderId }) => {
    const res = await fetch(
      `https://offspring.codeteam.in/mobileApp/IMP/dbprocess/mcp/api/sendChallanToTelegram.php?orderId=${orderId}`
    );
    const data = await res.json();

    return {
      content: [{ type: "text", text: JSON.stringify(data) }]
    };
  }
);

const app = express();

app.post("/mcp", async (req, res) => {
  const transport = new StreamableHTTPServerTransport({
    sessionIdGenerator: undefined
  });

  res.on("close", () => {
    transport.close();
  });

  await mcp.connect(transport);
  await transport.handleRequest(req, res, req.body);
});

app.get("/", (_req, res) => {
  res.send("Imprello MCP running");
});

const port = process.env.PORT || 4000;
app.listen(port, () => {
  console.log(`Imprello MCP listening on port ${port}`);
});

















