<h1 align="center" style="font-weight: bold;">KawalTani</h1>

<p align="center">
 <a href="#tech">Technologies</a> • 
 <a href="#started">Getting Started</a> • 
  <a href="#routes">API Endpoints</a> •
 <a href="#colab">Collaborators</a> 
</p>

<p align="center">
    <b>Kawaltani is an IoT-based system developed to support the monitoring of agricultural land and plant growth. This system provides real-time information on location, plant conditions, and land conditions, as well as alerts and action recommendations when discrepancies arise. With features such as pattern visualization, data trends, and a chatbot, Kawaltani is designed to assist farmers in land management more efficiently and based on data. </b>
</p>

<h2 id="technologies">Technology</h2>

- Laravel
- MySQL
- OpenAI

<h2 id="started">Getting started</h2>

<h3>Prerequisites</h3>

Here you list all prerequisites necessary for running the project:

- [Laravel](https://laravel.com/docs/11.x/)
- [OpenAI](https://platform.openai.com/docs/overview)

## How to use

1. **Set up the OpenAI API:**

   - If you're new to the OpenAI API, [sign up for an account](https://platform.openai.com/signup).
   - Follow the [Quickstart](https://platform.openai.com/docs/quickstart) to retrieve your API key.

2. **Set the OpenAI API key:**

   2 options:

   - Set the `OPENAI_API_KEY` environment variable [globally in your system](https://platform.openai.com/docs/libraries#create-and-export-an-api-key)
   - Set the `OPENAI_API_KEY` environment variable in the project: Create a `.env` file at the root of the project and add the following line

    ```bash
   OPENAI_API_KEY=<your_api_key>
   ```

4. **Clone the Repository:**

   ```bash
   git clone https://github.com/vinanamira/kawaltani-chatbot-be.git
   ```
   
5. **Enter the Project Directory:**

   After the cloning process is complete, enter the folder of the newly created project:

   ```bash
   cd kawaltani-chatbot-be
   ```

6. **Install dependencies:**

   Run in the project root:

   ```bash
   composer install
   ```

7. **Run the app:**

   ```bash
   php artisan serve
   ```

   The app will be available at [`http://127.0.0.1:8000`](http://127.0.0.1:8000).

<h2 id="routes">📍 API Endpoints</h2>

Here is a list of the main API routes with the expected request bodies.
​
| Route               | Description                                          
|----------------------|-----------------------------------------------------
| <kbd>POST /api/chat/send</kbd>     | Sending a new message and getting a reply from the AI [request details](#post-send-detail)
| <kbd>GET /api/chat/names</kbd>     | Taking all titles from the conversation history [response details](#get-all-chat-detail)
| <kbd>GET /api/chat/history/{name_chat}</kbd>     | Taking message history details from a conversation based on its title [response details](#get-chat-detail)
| <kbd>DELETE /api/chat/history/{name_chat}</kbd>     | Deleting a conversation history based on its title [response details](#delete-chat-detail)
| <kbd>PUT /api/chat/rename-chat/{name_chat}</kbd>     | Changing the title of an existing conversation history [request details](#rename-chat-detail)

<h3 id="post-send-detail">POST /api/chat/send</h3>

**REQUEST**
```json
{
  "message": "Saya mau melihat data pH  di area 2 tanggal 19 Mei",
  "name_chat": ""
}
```

**RESPONSE**
```json
{
    "message": "Saya mau melihat data pH  di area 2 tanggal 19 Mei",
    "response": "Ringkasan Kondisi Lahan:\n\nTanggal 19 Mei 2025:\n- Sensor pH Area 2: Terdapat lonjakan pH hingga mencapai angka 9. Kemungkinan area lahan di sekitar sensor tersebut saat ini sedang menjadi sangat basa. Disarankan untuk dilakukan pengecekan lebih lanjut terkait penyebab dan tindakan rektifkasinya.",
    "name_chat": "Saya mau melihat data pH  di area 2 tang..."
}
```
<h3 id="get-all-chat-detail">GET /api/chat/names</h3>

**RESPONSE**
```json
[
    {
        "session_id": 3,
        "name_chat": "Saya mau melihat data pH  di area 2 tang...",
        "updated_at": "2025-07-31T07:22:41.000000Z"
    },
    {
        "session_id": 2,
        "name_chat": "TestWhitebox",
        "updated_at": "2025-07-31T07:21:54.000000Z"
    },
    {
        "session_id": 1,
        "name_chat": "Testing",
        "updated_at": "2025-07-29T19:47:31.000000Z"
    }
]
```

<h3 id="get-chat-detail">GET /api/chat/history/{name_chat}</h3>

**RESPONSE**
```json
[
    {
        "message": "Saya mau melihat data pH  di area 2 tanggal 19 Mei",
        "response": "Pada tanggal 19 Mei 2025, data sensor menunjukkan kondisi lahan sebagai berikut: PH pada area 2 mencapai angka 9. Temuan ini mengindikasikan tingkat keasaman yang relatif tinggi, yang sebaiknya dijaga agar tidak terus meningkat dan berdampak buruk pada ketersediaan hara bagi tanaman.",
        "sent_at": "2025-07-31T07:21:54.000000Z"
    },
    {
        "message": "Saya mau melihat data pH  di area 2 tanggal 19 Mei",
        "response": "Ringkasan Kondisi Lahan:\nPada tanggal 19 Mei 2025, di area 2, nilai pH mencapai tingkat tertinggi dengan angka 9. Data ini menandakan kondisi lahan sedikit basa pada area tersebut. Informasi ini penting untuk pemantauan kesehatan dan kebutuhan perawatan tanaman, terutama tanaman tertentu yang peka terhadap keasaman tanah.",
        "sent_at": "2025-07-31T07:22:27.000000Z"
    }
]
```

<h3 id="delete-chat-detail">DELETE /api/chat/history/{name_chat}</h3>

**RESPONSE**
```json
{
    "message": "Percakapan berhasil dihapus."
}
```

<h3 id="#rename-chat-detail">PUT /api/chat/rename-chat/{name_chat}</h3>

**REQUEST**
```json
{
  "newName": "Data tanggal 19 Mei"
}
```

**RESPONSE**
```json
{
    "message": "Nama chat berhasil diganti."
}
```


<h2 id="colab">🤝 Collaborators</h2>

Special thank you for all people that contributed for this project.

<table>
  <tr>
    <td align="center">
      <a href="https://github.com/annisasha">
        <img src="https://avatars.githubusercontent.com/u/152659249?v=4" width="100px;" alt="Fernanda Kipper Profile Picture"/><br>
        <sub>
          <b>Annisa Shafira</b>
        </sub>
      </a>
    </td>
    <td align="center">
      <a href="https://github.com/shalmanrafli30">
        <img src="https://avatars.githubusercontent.com/u/151373806?v=4" width="100px;" alt="Shalman Rafli Picture"/><br>
        <sub>
          <b>Shalman Rafli</b>
        </sub>
      </a>
    </td>
</table>


<h3>Documentations that might help</h3>

OpenAI PHP Client Documentation : https://github.com/openai-php/client
