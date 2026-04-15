import express from "express";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import { z } from "zod";

const API_BASE = "https://offspring.codeteam.in/mobileApp/IMP/dbprocess/mcp/api";

const mcp = new McpServer({
  name: "imprello-mcp",
  version: "1.1.0"
});

// -------------------- Existing Tools --------------------

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
    const res = await fetch(`${API_BASE}/sendChallanToTelegram.php?orderId=${orderId}`);
    const data = await res.json();
    return {
      content: [{ type: "text", text: JSON.stringify(data) }]
    };
  }
);

// -------------------- New Telegram Queue Tools --------------------

mcp.tool(
  "getPendingTelegramDoc",
  {},
  async () => {
    const res = await fetch(`${API_BASE}/getPendingTelegramDoc.php`);
    const data = await res.json();
    return {
      content: [{ type: "text", text: JSON.stringify(data) }]
    };
  }
);

mcp.tool(
  "markTelegramDocStatus",
  {
    id: z.number(),
    status: z.enum(["pending", "processing", "done", "error"]),
    remarks: z.string().optional().default("")
  },
  async ({ id, status, remarks }) => {
    const url =
      `${API_BASE}/markTelegramDocStatus.php` +
      `?id=${encodeURIComponent(id)}` +
      `&status=${encodeURIComponent(status)}` +
      `&remarks=${encodeURIComponent(remarks)}`;

    const res = await fetch(url);
    const data = await res.json();

    return {
      content: [{ type: "text", text: JSON.stringify(data) }]
    };
  }
);

mcp.tool(
  "upsertDealerPrice",
  {
    dealerId: z.number(),
    prodId: z.number().optional(),
    pdctCode: z.string().optional(),
    price: z.number(),
    createdBy: z.string().optional().default("mcp_auto"),
    remarks: z.string().optional().default("Updated via MCP")
  },
  async ({ dealerId, prodId, pdctCode, price, createdBy, remarks }) => {
    const params = new URLSearchParams();

    params.append("dealerId", String(dealerId));
    params.append("price", String(price));

    if (prodId) params.append("prodId", String(prodId));
    if (pdctCode) params.append("pdctCode", pdctCode);

    params.append("createdBy", createdBy);
    params.append("remarks", remarks);

    const res = await fetch(`${API_BASE}/upsertDealerPrice.php`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: params.toString()
    });

    const data = await res.json();

    return {
      content: [{ type: "text", text: JSON.stringify(data) }]
    };
  }
);
mcp.tool(
  "sendInvoice",
  {
    orderId: z.number().optional(),
    invNo: z.string().optional()
  },
  async ({ orderId, invNo }) => {
    const params = new URLSearchParams();

    if (orderId) params.append("orderId", String(orderId));
    if (invNo) params.append("invNo", invNo);

    const res = await fetch(`${API_BASE}/sendInvoiceToTelegram.php`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: params.toString()
    });

    const data = await res.json();

    return {
      content: [{ type: "text", text: JSON.stringify(data) }]
    };
  }
);
// -------------------- Express App --------------------

const app = express();
app.use(express.json());

app.post("/mcp", async (req, res) => {
  try {
    const transport = new StreamableHTTPServerTransport({
      sessionIdGenerator: undefined
    });

    res.on("close", () => {
      transport.close();
    });

    await mcp.connect(transport);
    await transport.handleRequest(req, res, req.body);
  } catch (err) {
    console.error("MCP route error:", err);
    if (!res.headersSent) {
      res.status(500).json({
        error: "MCP route failed",
        details: err instanceof Error ? err.message : String(err)
      });
    }
  }
});

app.get("/", (_req, res) => {
  res.send("Imprello MCP running");
});

const port = process.env.PORT || 4000;
app.listen(port, () => {
  console.log(`Imprello MCP listening on port ${port}`);
});